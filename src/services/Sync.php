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
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQueryInterface;
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
 *  - The sync watermark only advances after Imageshop has actually answered
 *    and every fetched document was applied. An outage is logged as `failed`
 *    and changes nothing.
 *  - Each run stores its own document snapshot and queued jobs read the
 *    snapshot of the run that created them, so a later run cannot make
 *    queued jobs skip documents. A job whose snapshot has been pruned fetches
 *    its documents itself.
 *  - If some document requests or element saves fail, what succeeded is kept
 *    but the watermark is not advanced, so the rest is retried next run
 *    (`partial`).
 *  - Each element is locked for the duration of its update, so an editor
 *    saving an override at the same moment cannot have it overwritten.
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
        $fields = Craft::$app->getFields();

        // Ask per element type rather than via Fields::getAllLayouts(), which
        // only exists in Craft 5. getAllElementTypes() includes MatrixBlock on
        // Craft 4 and any element types plugins register.
        foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
            foreach ($fields->getLayoutsByType($elementType) as $layout) {
                if (!$layout->id) {
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
                    $result[$elementType][$layout->id] = array_values(array_unique($handles));
                }
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

            foreach ($this->buildScanQueries($elementType, array_keys($layouts)) as $query) {
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
    }

    /**
     * Builds the element queries that scan one element type for the given
     * field layouts. The caller still checks each element's real field layout
     * in PHP; these queries only narrow the scan where that is safe.
     *
     * `elements.fieldLayoutId` cannot be the filter: Craft 4 leaves it NULL
     * for entries (their layout comes from the entry type at runtime) and only
     * fills it for Matrix blocks, while Craft 5 fills it for everything. So:
     *
     *  - Entries are narrowed by entry type, which is authoritative on both
     *    versions.
     *  - Craft 4 Matrix blocks keep their content in a table per Matrix field,
     *    and `MatrixBlockQuery` only joins it when it knows the field (it can
     *    infer that from `id`, which a scan does not have), so blocks are
     *    scanned one Matrix field at a time. Craft 5 has no MatrixBlock
     *    element, so that branch is inert there.
     *  - Everything else is narrowed by `elements.fieldLayoutId` where it is
     *    set, and scanned in full where it is NULL.
     *
     * @param string $elementType Element class
     * @param int[] $layoutIds Field layouts that contain an Imageshop field
     * @return ElementQueryInterface[]
     */
    protected function buildScanQueries(string $elementType, array $layoutIds): array
    {
        /** @var ElementInterface|string $elementType */
        $build = fn(): ElementQueryInterface => $elementType::find()
            ->siteId('*')
            ->status(null)
            ->drafts(null)
            ->provisionalDrafts(null);

        if ($elementType === 'craft\\elements\\Entry') {
            $typeIds = [];
            foreach ($this->getAllEntryTypes() as $entryType) {
                if (in_array((int)$entryType->fieldLayoutId, $layoutIds, true)) {
                    $typeIds[] = (int)$entryType->id;
                }
            }

            return $typeIds ? [$build()->typeId($typeIds)] : [];
        }

        if ($elementType === 'craft\\elements\\MatrixBlock') {
            $fieldIds = (new Query())
                ->select('fieldId')
                ->distinct()
                ->from('{{%matrixblocktypes}}')
                ->where(['fieldLayoutId' => $layoutIds])
                ->column();

            return array_map(fn($fieldId) => $build()->fieldId((int)$fieldId), $fieldIds);
        }

        return [$build()->andWhere(['or', ['elements.fieldLayoutId' => $layoutIds], ['elements.fieldLayoutId' => null]])];
    }

    /**
     * All entry types, from whichever service owns them on this Craft version.
     *
     * @return \craft\models\EntryType[]
     */
    protected function getAllEntryTypes(): array
    {
        $entries = Craft::$app->getEntries();
        if (method_exists($entries, 'getAllEntryTypes')) {
            // Craft 5
            return $entries->getAllEntryTypes();
        }

        // Craft 4
        return Craft::$app->getSections()->getAllEntryTypes();
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
     * Fetches the given documents from Imageshop in the given languages.
     *
     * A document the API reports as nonexistent is simply absent from the
     * result; a request that fails counts as a failure and the caller decides
     * whether the run may advance the watermark.
     *
     * @param int[] $documentIds
     * @param string[] $languages
     * @return array{cache: array, failures: int} `cache` is [documentId => [lang => apiDoc]]
     */
    public function fetchDocuments(array $documentIds, array $languages): array
    {
        $service = Plugin::getInstance()->service;
        $cache = [];
        $failures = 0;

        foreach (array_unique(array_map('intval', $documentIds)) as $documentId) {
            if (!$documentId) {
                continue;
            }
            foreach (array_unique(array_filter($languages)) as $lang) {
                $doc = $service->getDocumentById($documentId, $lang);
                if ($doc === false) {
                    $failures++;
                } elseif ($doc) {
                    $cache[$documentId][$service->sanitizeLanguage($lang)] = $doc;
                }
            }
        }

        return ['cache' => $cache, 'failures' => $failures];
    }

    /**
     * Asks Imageshop which documents changed since the last successful run
     * and fetches the latest metadata for the ones in use, plus any in-use
     * document missing a text block for a site language. Nothing is stored.
     *
     * Metadata is fetched once per language, where the language set is the
     * union of the languages already present in the affected values and the
     * Imageshop language of every Craft site.
     *
     * Returns the fetched `cache`, a `status` of `ok`, `partial` (some
     * document requests failed) or `failed` (Imageshop could not be asked at
     * all), the number of `failures`, and the two watermarks the caller
     * chooses between when storing the run: `watermark` (taken before the
     * changed-ids request, so a document changed mid-run is reported next
     * time) and `previousWatermark`.
     *
     * @return array{cache: array, status: string, failures: int, watermark: string, previousWatermark: string}
     */
    public function fetchChangedDocuments(): array
    {
        $service = Plugin::getInstance()->service;

        $previousWatermark = $service->getDateLastUpdated();
        $watermark = Db::prepareDateForDb(new \DateTime());

        $changed = $service->getRecentlyUpdatedDocumentIds();
        if ($changed === null) {
            return ['cache' => [], 'status' => 'failed', 'failures' => 1, 'watermark' => $watermark, 'previousWatermark' => $previousWatermark];
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

        $fetched = $this->fetchDocuments(array_keys($inUse), array_keys($languages));

        return [
            'cache' => $fetched['cache'],
            'status' => $fetched['failures'] > 0 ? 'partial' : 'ok',
            'failures' => $fetched['failures'],
            'watermark' => $watermark,
            'previousWatermark' => $previousWatermark,
        ];
    }

    /**
     * Fetches changed documents and stores them as a new run snapshot.
     *
     * A partial fetch is stored with the previous watermark so the next run
     * asks for everything since then again; a failed fetch stores nothing.
     *
     * @return array{runId: ?int, cache: array, status: string, failures: int}
     */
    public function updateRecentlyUpdatedCache(): array
    {
        $fetch = $this->fetchChangedDocuments();

        if ($fetch['status'] === 'failed') {
            return ['runId' => null, 'cache' => [], 'status' => 'failed', 'failures' => $fetch['failures']];
        }

        $runId = $this->storeRun($fetch, $fetch['status'] === 'partial');

        return ['runId' => $runId, 'cache' => $fetch['cache'], 'status' => $fetch['status'], 'failures' => $fetch['failures']];
    }

    /**
     * Stores a fetch result as a run snapshot and returns the run id.
     *
     * @param array $fetch Result of fetchChangedDocuments()
     * @param bool $keepWatermark Store with the previous watermark instead of advancing
     */
    protected function storeRun(array $fetch, bool $keepWatermark): int
    {
        // The run vouches for the previous watermark until its work is done;
        // the target watermark is advanced into place by run() (inline) or by
        // the last completed job (queue mode). A run that must not advance
        // gets the previous watermark as its target too.
        return Plugin::getInstance()->service->setDocumentCache(
            $fetch['cache'],
            $fetch['previousWatermark'],
            $keepWatermark ? $fetch['previousWatermark'] : $fetch['watermark']
        );
    }

    /**
     * Queues one job per element/site combination that references a document
     * in the given run's cache. Jobs carry the run id (to read that run's
     * snapshot) and their document ids (to refetch if the snapshot is gone).
     *
     * The number of jobs is recorded on the run before any job is pushed; the
     * run's watermark advances when the last of them completes successfully.
     *
     * @param array $documentCache The run's cache
     * @param int $runId The run the cache belongs to
     * @return int Number of jobs queued
     */
    public function queueSyncJobs(array $documentCache, int $runId): int
    {
        $service = Plugin::getInstance()->service;

        if (!$runId) {
            return 0;
        }

        $usages = empty($documentCache) ? [] : iterator_to_array($this->findUsages(array_keys($documentCache)), false);
        $total = count($usages);

        $service->setSyncRunJobs($runId, $total);

        foreach ($usages as $i => $usage) {
            $documentIds = [];
            foreach ($usage['fields'] as $ids) {
                foreach ($ids as $id) {
                    $documentIds[$id] = true;
                }
            }

            Craft::$app->getQueue()->ttr(3600)->push(new SyncJob([
                'runId' => $runId,
                'elementType' => $usage['elementType'],
                'elementId' => $usage['elementId'],
                'siteId' => $usage['siteId'],
                'fieldHandles' => array_keys($usage['fields']),
                'documents' => array_keys($documentIds),
                'languages' => $usage['languages'],
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
     * The element's row is locked for the duration, so an editor saving a
     * local override at the same moment either lands before this update is
     * read (and is preserved) or waits until it is written. Without the lock
     * the sync could read an element, an editor could save an override, and
     * the sync's save would then overwrite it.
     *
     * @param string $elementType Element class
     * @param int $elementId
     * @param int $siteId
     * @param string[] $fieldHandles Imageshop field handles on the element
     * @param array|null $documentCache The cache to apply, or null for the latest run's
     * @return bool true when the element was changed and saved, false when the cache changed nothing
     * @throws \RuntimeException when the element needed updating but could not be saved
     */
    public function syncElement(string $elementType, int $elementId, int $siteId, array $fieldHandles, ?array $documentCache = null): bool
    {
        $documentCache ??= Plugin::getInstance()->service->getDocumentCache();
        if (empty($documentCache) || empty($fieldHandles)) {
            return false;
        }

        $db = Craft::$app->getDb();

        return $db->transaction(function() use ($db, $elementType, $elementId, $siteId, $fieldHandles, $documentCache): bool {
            // Row lock on the element until this transaction ends.
            $db->createCommand(
                'SELECT [[id]] FROM ' . Table::ELEMENTS . ' WHERE [[id]] = :id FOR UPDATE',
                [':id' => $elementId]
            )->queryScalar();

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
            if (!Craft::$app->getElements()->saveElement($element, false, false, true)) {
                $errors = implode('; ', array_map(fn($e) => implode(', ', $e), $element->getErrors())) ?: 'a save hook declined the save';
                throw new \RuntimeException("Could not save {$elementType} {$elementId} (site {$siteId}) after syncing Imageshop metadata: {$errors}");
            }

            return true;
        });
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
     * `status` is `success`, `no_changes`, `partial` (some document requests
     * or, inline, some element saves failed; what succeeded is kept and the
     * watermark is held so the rest is retried) or `failed` (Imageshop
     * unreachable, nothing changed).
     *
     * @param bool $inline Process elements now instead of queueing jobs
     * @return array{runId: ?int, documentsChanged: int, elements: int, failures: int, inline: bool, status: string, details: array}
     */
    public function run(bool $inline = false): array
    {
        $service = Plugin::getInstance()->service;

        $fetch = $this->fetchChangedDocuments();

        if ($fetch['status'] === 'failed') {
            $service->logSync(0, 0, 'failed', []);

            return [
                'runId' => null,
                'documentsChanged' => 0,
                'elements' => 0,
                'failures' => $fetch['failures'],
                'inline' => $inline,
                'status' => 'failed',
                'details' => [],
            ];
        }

        $cache = $fetch['cache'];
        $documentsChanged = count($cache);
        $details = $service->buildSyncDetails($cache);
        $failures = $fetch['failures'];
        $elements = 0;

        // Store the snapshot vouching for the previous watermark first, do the
        // work, and advance the watermark last. If this process dies while
        // queueing jobs or saving elements, the next run asks for the same
        // changes again instead of skipping the ones that never got a job.
        // A partial fetch keeps the previous watermark as its target.
        $runId = $this->storeRun($fetch, $failures > 0);

        if ($inline) {
            if (!empty($cache)) {
                $usages = iterator_to_array($this->findUsages(array_keys($cache)), false);
                foreach ($usages as $usage) {
                    try {
                        if ($this->syncElement($usage['elementType'], $usage['elementId'], $usage['siteId'], array_keys($usage['fields']), $cache)) {
                            $elements++;
                        }
                    } catch (\Throwable $e) {
                        Craft::error($e->getMessage(), __METHOD__);
                        $failures++;
                    }
                }
            }

            // Inline, this process did the work, so it advances the watermark
            // itself unless something failed.
            if ($failures === 0) {
                $service->advanceSyncWatermark($runId, $fetch['watermark']);
            }
        } else {
            // Queue mode: the watermark advances when the last job completes;
            // see ImageShop::completeSyncJob(). A run that queues nothing
            // completes immediately.
            $elements = $this->queueSyncJobs($cache, $runId);
        }

        if ($failures > 0) {
            $status = 'partial';
        } else {
            $status = $elements > 0 ? 'success' : 'no_changes';
        }

        $service->logSync($documentsChanged, $elements, $status, $details);

        return [
            'runId' => $runId,
            'documentsChanged' => $documentsChanged,
            'elements' => $elements,
            'failures' => $failures,
            'inline' => $inline,
            'status' => $status,
            'details' => $details,
        ];
    }
}
