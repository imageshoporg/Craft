<?php
/**
 * Imageshop plugin for Craft CMS
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://www.imageshop.org
 * @copyright Copyright (c) 2022 Imageshop
 */

namespace Imageshop\Imageshop\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use Imageshop\Imageshop\fields\ImageShopField;
use Imageshop\Imageshop\ImageShop as Plugin;
use Imageshop\Imageshop\jobs\Sync as SyncJob;
use Imageshop\Imageshop\models\ImageShop as ImageModel;

/**
 * Metadata sync engine.
 *
 * Discovers every element that holds an Imageshop field value through Craft's
 * field layouts and element queries, and writes refreshed metadata back
 * through the element lifecycle. Nothing here touches a content table or
 * issues raw SQL against element data, so it works unchanged on Craft 4
 * (per-field content columns) and Craft 5 (`elements_sites.content` JSON).
 *
 * Saving through `Elements::saveElement()` is what makes the sync visible:
 * it invalidates element caches (including the owner of a nested entry or
 * Matrix block), updates the search index and fires `afterSave` events for
 * other plugins. `resaving` is set on each element so `dateUpdated` is left
 * alone, the same way Craft's own "Resave elements" behaves.
 *
 * Local alt text / description overrides live in the image JSON's `overrides`
 * block and are never written by the sync; see `mapDocumentFields()`.
 *
 * @since 3.3.0
 */
class Sync extends Component
{
    /**
     * Returns the field layouts that contain at least one Imageshop field,
     * grouped by element type.
     *
     * @return array<string, array<int, string[]>> [elementType => [layoutId => [fieldHandle, ...]]]
     */
    public function getImageshopFieldLayouts(): array
    {
        $result = [];

        foreach (Craft::$app->getFields()->getAllLayouts() as $layout) {
            if (!$layout->id || !$layout->type) {
                continue;
            }

            $handles = [];
            foreach ($layout->getCustomFields() as $field) {
                if ($field instanceof ImageShopField) {
                    // Craft 5 field instances may carry their own handle, so
                    // read it from the layout's field, not the global field.
                    $handles[] = $field->handle;
                }
            }

            if (!empty($handles)) {
                $result[$layout->type][$layout->id] = array_values(array_unique($handles));
            }
        }

        return $result;
    }

    /**
     * Yields every element/site combination that holds an Imageshop field
     * value, optionally limited to values referencing the given document ids.
     *
     * Drafts and provisional drafts are included so a draft applied after a
     * sync cannot reintroduce stale metadata. Revisions are immutable history
     * and are skipped.
     *
     * Each yielded item has the shape:
     * `['elementType' => string, 'elementId' => int, 'siteId' => int,
     *   'fields' => [handle => [documentId, ...]], 'languages' => [lang, ...]]`
     *
     * @param int[]|null $documentIds Limit to usages of these documents, or null for all
     * @return \Generator<array>
     */
    public function findUsages(?array $documentIds = null): \Generator
    {
        $wanted = $documentIds !== null ? array_flip(array_map('intval', $documentIds)) : null;

        foreach ($this->getImageshopFieldLayouts() as $elementType => $layouts) {
            if (!class_exists($elementType) || !is_subclass_of($elementType, ElementInterface::class)) {
                continue;
            }

            /** @var ElementInterface|string $elementType */
            $query = $elementType::find()
                ->andWhere(['elements.fieldLayoutId' => array_keys($layouts)])
                ->siteId('*')
                ->status(null)
                ->drafts(null)
                ->provisionalDrafts(null);

            foreach ($query->each() as $element) {
                /** @var ElementInterface $element */
                $layoutId = $element->getFieldLayout()?->id ?? $element->fieldLayoutId ?? null;
                $handles = $layouts[$layoutId] ?? null;
                if (!$handles) {
                    continue;
                }

                $fields = [];
                $languages = [];

                foreach ($handles as $handle) {
                    $models = $element->getFieldValue($handle);
                    if (!is_array($models) || empty($models)) {
                        continue;
                    }

                    $ids = [];
                    foreach ($models as $model) {
                        if (!$model instanceof ImageModel) {
                            continue;
                        }
                        $id = (int)$model->getDocumentId();
                        if ($id && ($wanted === null || isset($wanted[$id]))) {
                            $ids[$id] = true;
                        }
                        $json = $model->getJson();
                        if (is_array($json) && isset($json['text']) && is_array($json['text'])) {
                            foreach (array_keys($json['text']) as $lang) {
                                $languages[(string)$lang] = true;
                            }
                        }
                    }

                    if (!empty($ids)) {
                        $fields[$handle] = array_keys($ids);
                    }
                }

                if (empty($fields)) {
                    continue;
                }

                yield [
                    'elementType' => $elementType,
                    'elementId' => (int)$element->id,
                    'siteId' => (int)$element->siteId,
                    'fields' => $fields,
                    'languages' => array_keys($languages),
                ];
            }
        }
    }

    /**
     * Asks Imageshop which documents changed since the last run, fetches the
     * latest metadata for the ones that are actually in use, and stores it in
     * the document cache.
     *
     * Metadata is fetched once per language, where the language set is the
     * union of the languages already present in the affected values and the
     * Imageshop language of every Craft site. That lets a site added after an
     * image was picked receive its text block on the next sync.
     *
     * @return array The new document cache: [documentId => [lang => apiDoc]]
     */
    public function updateRecentlyUpdatedCache(): array
    {
        $service = Plugin::getInstance()->service;
        $changed = array_map('intval', $service->getRecentlyUpdatedDocumentIds());
        $cache = [];

        if (!empty($changed)) {
            $inUse = [];
            $languages = [];

            foreach ($this->findUsages($changed) as $usage) {
                foreach ($usage['fields'] as $ids) {
                    foreach ($ids as $id) {
                        $inUse[$id] = true;
                    }
                }
                foreach ($usage['languages'] as $lang) {
                    $languages[$lang] = true;
                }
            }

            if (!empty($inUse)) {
                foreach (Craft::$app->getSites()->getAllSites() as $site) {
                    $lang = $service->getImageshopLanguageForSite($site);
                    if ($lang) {
                        $languages[$lang] = true;
                    }
                }

                foreach (array_keys($inUse) as $documentId) {
                    foreach (array_keys($languages) as $lang) {
                        $doc = $service->getDocumentById($documentId, $lang);
                        if ($doc) {
                            $cache[$documentId][$service->sanitizeLanguage($lang)] = $doc;
                        }
                    }
                }
            }
        }

        // Always write the cache and bump the timestamp, even when empty, so
        // the next run only asks for documents changed after this one.
        $service->setDocumentCache($cache);

        return $cache;
    }

    /**
     * Queues one job per element/site combination that references a document
     * in the cache.
     *
     * @param array|null $documentCache The cache to use, or null to load the stored one
     * @return int Number of jobs queued
     */
    public function queueSyncJobs(?array $documentCache = null): int
    {
        $documentCache ??= Plugin::getInstance()->service->getDocumentCache();
        if (empty($documentCache)) {
            return 0;
        }

        $usages = iterator_to_array($this->findUsages(array_keys($documentCache)), false);
        $total = count($usages);

        foreach ($usages as $i => $usage) {
            Craft::$app->getQueue()->ttr(3600)->push(new SyncJob([
                'elementType' => $usage['elementType'],
                'elementId' => $usage['elementId'],
                'siteId' => $usage['siteId'],
                'fieldHandles' => array_keys($usage['fields']),
                'index' => $i + 1,
                'count' => $total,
            ]));
        }

        return $total;
    }

    /**
     * Applies the document cache to one element in one site and saves it
     * through the element lifecycle.
     *
     * @param string $elementType Element class
     * @param int $elementId
     * @param int $siteId
     * @param string[] $fieldHandles Imageshop field handles on the element
     * @param array|null $documentCache The cache to use, or null to load the stored one
     * @return bool Whether the element was changed and saved
     */
    public function syncElement(string $elementType, int $elementId, int $siteId, array $fieldHandles, ?array $documentCache = null): bool
    {
        $documentCache ??= Plugin::getInstance()->service->getDocumentCache();
        if (empty($documentCache) || empty($fieldHandles)) {
            return false;
        }

        $element = Craft::$app->getElements()->getElementById($elementId, $elementType, $siteId);
        if (!$element || $element->getIsRevision()) {
            return false;
        }

        $changed = false;
        foreach ($fieldHandles as $handle) {
            $models = $element->getFieldValue($handle);
            if (!is_array($models) || empty($models)) {
                continue;
            }

            $result = $this->applyDocumentCache($models, $documentCache);
            if ($result['changed']) {
                $element->setFieldValue($handle, $result['models']);
                $changed = true;
            }
        }

        if (!$changed) {
            return false;
        }

        // Leave dateUpdated alone; this is a metadata refresh, not an edit.
        $element->resaving = true;

        // Each site is its own job, so don't propagate. Skip validation so an
        // unrelated invalid field cannot block a metadata refresh.
        return Craft::$app->getElements()->saveElement($element, false, false, true);
    }

    /**
     * Returns the given image models with cached API metadata merged into
     * their `text` blocks. Models whose document is not in the cache, or whose
     * data would not change, are returned as-is.
     *
     * @param ImageModel[] $models
     * @param array $documentCache [documentId => [lang => apiDoc]]
     * @return array{models: array, changed: bool}
     */
    public function applyDocumentCache(array $models, array $documentCache): array
    {
        $service = Plugin::getInstance()->service;
        $out = [];
        $changed = false;

        foreach ($models as $model) {
            if ($model instanceof ImageModel) {
                $documentId = (int)$model->getDocumentId();
                $json = $model->getJson();

                if ($documentId && is_array($json) && isset($documentCache[$documentId]) && is_array($documentCache[$documentId])) {
                    $mapped = $service->mapDocumentFields($json, $documentCache[$documentId]);
                    if ($mapped !== $json) {
                        $out[] = new ImageModel($mapped);
                        $changed = true;
                        continue;
                    }
                }
            }

            $out[] = $model;
        }

        return ['models' => $out, 'changed' => $changed];
    }

    /**
     * Runs a full sync: fetch changed documents, then either queue one job per
     * affected element/site or process them immediately. The run is logged.
     *
     * @param bool $inline Process elements now instead of queueing jobs
     * @return array{documentsChanged: int, elements: int, inline: bool, status: string, details: array}
     */
    public function run(bool $inline = false): array
    {
        $service = Plugin::getInstance()->service;

        $cache = $this->updateRecentlyUpdatedCache();
        $documentsChanged = count($cache);
        $details = $service->buildSyncDetails($cache);

        $elements = 0;
        if ($inline) {
            if (!empty($cache)) {
                $usages = iterator_to_array($this->findUsages(array_keys($cache)), false);
                foreach ($usages as $usage) {
                    if ($this->syncElement($usage['elementType'], $usage['elementId'], $usage['siteId'], array_keys($usage['fields']), $cache)) {
                        $elements++;
                    }
                }
            }
        } else {
            $elements = $this->queueSyncJobs($cache);
        }

        $status = $elements > 0 ? 'success' : 'no_changes';
        $service->logSync($documentsChanged, $elements, $status, $details);

        return [
            'documentsChanged' => $documentsChanged,
            'elements' => $elements,
            'inline' => $inline,
            'status' => $status,
            'details' => $details,
        ];
    }
}
