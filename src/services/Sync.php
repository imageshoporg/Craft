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
use craft\helpers\Db;
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
 * Reliability rules:
 *  - The sync watermark only advances after Imageshop has actually answered.
 *    An outage is logged as `failed` and changes it nothing.
 *  - Each run stores its own document snapshot and queued jobs read the
 *    snapshot of the run that created them, so a later run cannot make
 *    queued jobs skip documents.
 *  - If some document requests fail, what was fetched is applied but the
 *    watermark is kept, so the rest is retried next run (`partial`).
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
     * value matching the filters.
     *
     * A value is included when its document id is in `$documentIds` (null
     * means every document), or when `$requiredLanguages` is given and the
     * value's `text` block lacks one of those languages. The second filter is
     * how a Craft site added after an image was picked gets its text: the
     * document does not need to have changed in Imageshop.
     *
     * Drafts and provisional drafts are included so a draft applied after a
     * sync cannot reintroduce stale metadata. Revisions are immutable history
     * and are skipped.
     *
     * Each yielded item has the shape:
     * `['elementType' => string, 'elementId' => int, 'siteId' => int,
     *   'fields' => [handle => [documentId, ...]], 'languages' => [lang, ...]]`
     *
     * @param int[]|null $documentIds Documents to match, or null for all
     * @param string[]|null $requiredLanguages Languages every value should have a text block for
     * @return \Generator<array>
     */
    public function findUsages(?array $documentIds = null, ?array $requiredLanguages = null): \Generator
    {
        $wanted = $documentIds !== null ? array_flip(array_map('intval', $documentIds)) : null;
        $required = $requiredLanguages !== null ? array_values(array_unique(array_filter($requiredLanguages))) : [];

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
                        if (!$id) {
                            continue;
                        }

                        $json = $model->getJson();
                        $text = is_array($json) && isset($json['text']) && is_array($json['text']) ? $json['text'] : [];
                        foreach (array_keys($text) as $lang) {
                            $languages[(string)$lang] = true;
                        }

                        $include = $wanted === null || isset($wanted[$id]);
                        if (!$include && !empty($required)) {
                            foreach ($required as $lang) {
                                if (!isset($text[$lang])) {
                                    $include = true;
                                    break;
                                }
                            }
                        }

                        if ($include) {
                            $ids[$id] = true;
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
     * The Imageshop language code of every Craft site, deduplicated.
     *
     * @return string[]
     */
    public function getSiteLanguages(): array
    {
        $service = Plugin::getInstance()->service;
        $languages = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $lang = $service->getImageshopLanguageForSite($site);
            if ($lang) {
                $languages[$lang] = true;
            }
        }

        return array_keys($languages);
    }

    /**
     * Asks Imageshop which documents changed since the last successful run,
     * fetches the latest metadata for the ones in use (plus any in-use
     * document missing a text block for a site language), and stores the
     * result as a new run snapshot.
     *
     * Metadata is fetched once per language, where the language set is the
     * union of the languages already present in the affected values and the
     * Imageshop language of every Craft site.
     *
     * Returns `runId` (null when nothing was stored), the fetched `cache`,
     * a `status` of `ok`, `partial` (some document requests failed; the
     * watermark was not advanced) or `failed` (Imageshop could not be asked
     * at all; nothing was stored), and the number of `failures`.
     *
     * @return array{runId: ?int, cache: array, status: string, failures: int}
     */
    public function updateRecentlyUpdatedCache(): array
    {
        $service = Plugin::getInstance()->service;

        // Take the watermark before asking, so a document that changes while
        // this run is fetching is reported to the next run instead of lost.
        $previousWatermark = $service->getDateLastUpdated();
        $watermark = Db::prepareDateForDb(new \DateTime());

        $changed = $service->getRecentlyUpdatedDocumentIds();
        if ($changed === null) {
            return ['runId' => null, 'cache' => [], 'status' => 'failed', 'failures' => 1];
        }

        $siteLanguages = $this->getSiteLanguages();
        $inUse = [];
        $languages = array_fill_keys($siteLanguages, true);

        foreach ($this->findUsages($changed, $siteLanguages) as $usage) {
            foreach ($usage['fields'] as $ids) {
                foreach ($ids as $id) {
                    $inUse[$id] = true;
                }
            }
            foreach ($usage['languages'] as $lang) {
                $languages[$lang] = true;
            }
        }

        $cache = [];
        $failures = 0;

        foreach (array_keys($inUse) as $documentId) {
            foreach (array_keys($languages) as $lang) {
                $doc = $service->getDocumentById($documentId, $lang);
                if ($doc === false) {
                    $failures++;
                } elseif ($doc) {
                    $cache[$documentId][$service->sanitizeLanguage($lang)] = $doc;
                }
            }
        }

        $status = $failures > 0 ? 'partial' : 'ok';

        // A partial run keeps the previous watermark so everything since then
        // is asked for again next time; only a clean run advances it.
        $runId = $service->setDocumentCache($cache, $status === 'partial' ? $previousWatermark : $watermark);

        return ['runId' => $runId, 'cache' => $cache, 'status' => $status, 'failures' => $failures];
    }

    /**
     * Queues one job per element/site combination that references a document
     * in the given run's cache. Jobs carry the run id and read that run's
     * snapshot when they execute.
     *
     * @param array $documentCache The run's cache
     * @param int $runId The run the cache belongs to
     * @return int Number of jobs queued
     */
    public function queueSyncJobs(array $documentCache, int $runId): int
    {
        if (empty($documentCache) || !$runId) {
            return 0;
        }

        $usages = iterator_to_array($this->findUsages(array_keys($documentCache)), false);
        $total = count($usages);

        foreach ($usages as $i => $usage) {
            Craft::$app->getQueue()->ttr(3600)->push(new SyncJob([
                'runId' => $runId,
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
     * Applies a document cache to one element in one site and saves it
     * through the element lifecycle.
     *
     * @param string $elementType Element class
     * @param int $elementId
     * @param int $siteId
     * @param string[] $fieldHandles Imageshop field handles on the element
     * @param array|null $documentCache The cache to apply, or null for the latest run's
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
     * `status` is `success`, `no_changes`, `partial` (applied what could be
     * fetched, will retry the rest) or `failed` (Imageshop unreachable,
     * nothing changed).
     *
     * @param bool $inline Process elements now instead of queueing jobs
     * @return array{runId: ?int, documentsChanged: int, elements: int, inline: bool, status: string, details: array}
     */
    public function run(bool $inline = false): array
    {
        $service = Plugin::getInstance()->service;

        $fetch = $this->updateRecentlyUpdatedCache();

        if ($fetch['status'] === 'failed') {
            $service->logSync(0, 0, 'failed', []);

            return [
                'runId' => null,
                'documentsChanged' => 0,
                'elements' => 0,
                'inline' => $inline,
                'status' => 'failed',
                'details' => [],
            ];
        }

        $cache = $fetch['cache'];
        $runId = $fetch['runId'];
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
            $elements = $this->queueSyncJobs($cache, $runId);
        }

        if ($fetch['status'] === 'partial') {
            $status = 'partial';
        } else {
            $status = $elements > 0 ? 'success' : 'no_changes';
        }

        $service->logSync($documentsChanged, $elements, $status, $details);

        return [
            'runId' => $runId,
            'documentsChanged' => $documentsChanged,
            'elements' => $elements,
            'inline' => $inline,
            'status' => $status,
            'details' => $details,
        ];
    }
}
