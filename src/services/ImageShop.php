<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://www.imageshop.org
 * @copyright Copyright (c) 2022 Imageshop
 */

namespace Imageshop\Imageshop\services;

use Imageshop\Imageshop\ImageShop as Plugin;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * @author    Imageshop
 * @package   Imageshop
 * @since     2.0.0
 */
class ImageShop extends Component
{
    // Constants
    // =========================================================================

    /**
     * Durable store for permalinks. Deliberately not a cache — see
     * getCachedPermalink().
     */
    public const PERMALINK_TABLE = '{{%imageshop-dam_permalinks}}';

    /**
     * Cache key prefix for the permalink back-off. Only failures are cached
     * here; successes live in the database.
     */
    private const PERMALINK_FAILURE_PREFIX = 'imageshop_permalink_failed_';

    /**
     * How long to stop retrying a permalink after the API fails, in seconds.
     * Long enough to ride out a blip without hammering, short enough that
     * images come back without operator intervention.
     */
    private const PERMALINK_FAILURE_TTL = 300;

    /**
     * Upper bound on requested dimensions, so the anonymous permalink action
     * cannot be used to create unbounded rows and API-side permalinks.
     */
    private const PERMALINK_MAX_DIMENSION = 5000;

    // Private Properties
    // =========================================================================

    /**
     * Per-request memo of stored permalinks, keyed by documentId, each mapping
     * "{width}x{height}" => url. Collapses a multi-width srcset into one query.
     *
     * @var array<int, array<string, string>>
     */
    private array $_permalinkMemo = [];

    // Public Methods
    // =========================================================================

    /**
     * Get a temporary access token
     *
     * @return ?string
     **/
    public function getTemporaryToken(): ?string
    {
        $settings = Plugin::$plugin->getSettings();

        if (empty($settings->token)) {
            return null;
        }

        $params = [];
        if (!empty($settings->key)) {
            $params['query'] = ['privateKey' => App::parseEnv($settings->key)];
        }

        $response = $this->_request('GET', '/Login/GetTemporaryToken', $params);

        if (!is_string($response) || $response === '') {
            return null;
        }

        $decoded = Json::decodeIfJson($response);
        $token = is_string($decoded) ? $decoded : $response;
        $token = trim($token, "\" \t\n\r\0\x0B");

        return $token !== '' ? $token : null;
    }

    /**
     * Builds the ImageShop picker popup URL using a fresh temporary token.
     * Only known option keys are honored; callers cannot inject arbitrary query parameters.
     *
     * @param array $options Field options: showSizeDialogue, showCropDialogue, showDescription, allowMultiple, sizes, culture
     * @return ?string Full picker URL, or null if a temporary token could not be obtained
     **/
    public function getPickerUrl(array $options): ?string
    {
        $token = $this->getTemporaryToken();
        if (!$token) {
            return null;
        }

        $settings = Plugin::$plugin->getSettings();
        $culture = !empty($options['culture']) ? $options['culture'] : $settings->language;

        $query = http_build_query([
            'IMAGESHOPTOKEN'     => $token,
            'SHOWSIZEDIALOGUE'   => !empty($options['showSizeDialogue']) ? 'true' : 'false',
            'SHOWCROPDIALOGUE'   => !empty($options['showCropDialogue']) ? 'true' : 'false',
            'SHOWDESCRIPTION'    => !empty($options['showDescription']) ? 'true' : 'false',
            'IMAGESHOPSIZES'     => (string) ($options['sizes'] ?? ''),
            'FORMAT'             => 'json',
            'SETDOMAIN'          => 'false',
            'CULTURE'            => $culture,
            'IMAGESHOPLANGUAGE'  => $culture,
            'ENABLEMULTISELECT'  => !empty($options['allowMultiple']) ? 'true' : 'false',
        ]);

        return sprintf('%s?%s', 'https://client.imageshop.no/insertimage2.aspx', trim($query, '&'));
    }
    /**
     * Gets a document from the imageshop API
     *
     *
     * @param int $documentId Document Id
     * @param string $language Requested language
     * @return array|false|null Document data; null when there is no such document; false when the request failed and should be retried
     **/
    public function getDocumentById(int $documentId, string $language): array|false|null
    {
        if (!$documentId) {
            return null;
        }

        $language = $this->sanitizeLanguage($language);

        $response = $this->_request('GET','/Document/GetDocumentById',[
            'query' => [
                'DocumentID' => $documentId,
                'language' => $language
            ]
        ]);

        // false: the request itself failed (outage, HTTP error). The sync
        // treats that differently from null, which means "no such document".
        if ($response === false) {
            return false;
        }

        if (!is_string($response)) {
            return null;
        }

        // The API answers 200 with a literal `null` body for a document that
        // does not exist; that is a real answer. A body that is not JSON at
        // all (a proxy error page, a truncated response) is not, and must be
        // retried rather than recorded as "no document".
        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::error("Imageshop API returned a non-JSON body for document {$documentId}", __METHOD__);
            return false;
        }

        // A document is a JSON object (associative array); a list is not one.
        return is_array($decoded) && !ArrayHelper::isIndexed($decoded, true) ? $decoded : null;
    }

    /**
     * Merges per-language API metadata into the stored picker JSON.
     *
     * Only the `text` block is written. Every language in `$dataFromApi` is
     * applied, and a language the stored value did not have yet gets a new
     * block, so a Craft site added after the image was picked receives its
     * text on the next sync. Local editor overrides live in `overrides` and
     * are never touched here — that is what lets the sync run freely without
     * destroying context-specific alt text entered in Craft.
     *
     * @param array $dataFromPicker Stored image JSON as produced by the picker
     * @param array $dataFromApi Per-language API responses: [lang => apiDoc]
     * @return array The updated image JSON
     **/
    public function mapDocumentFields(array $dataFromPicker, array $dataFromApi): array
    {
        $mapped = $dataFromPicker;

        // Each apiDoc has top-level fields: AltText, Description, Credits, etc.
        $fieldMap = [
            'altText' => 'AltText',
            'description' => 'Description',
            'title' => 'Name',
            'credits' => 'Credits',
            'rights' => 'Rights',
            'tags' => 'Tags',
        ];

        // Never apply older metadata over newer. Sync runs can be applied out
        // of order (two queue workers, a retried job), so the stored value
        // remembers the document's `Changed` timestamp it came from and a
        // cache from an earlier version of the document is ignored.
        $apiChanged = null;
        $apiChangedTs = null;
        foreach ($dataFromApi as $apiDoc) {
            if (is_array($apiDoc) && !empty($apiDoc['Changed']) && is_string($apiDoc['Changed'])) {
                $ts = strtotime($apiDoc['Changed']);
                if ($ts !== false && ($apiChangedTs === null || $ts > $apiChangedTs)) {
                    $apiChangedTs = $ts;
                    $apiChanged = $apiDoc['Changed'];
                }
            }
        }

        $storedChangedTs = isset($mapped['sync']['changed']) && is_string($mapped['sync']['changed'])
            ? strtotime($mapped['sync']['changed'])
            : false;

        if ($apiChangedTs !== null && $storedChangedTs !== false && $storedChangedTs > $apiChangedTs) {
            return $dataFromPicker;
        }

        if (!isset($mapped['text']) || !is_array($mapped['text'])) {
            $mapped['text'] = [];
        }

        foreach ($dataFromApi as $lang => $apiDoc) {
            if (!is_array($apiDoc)) {
                continue;
            }

            $textBlock = $mapped['text'][$lang] ?? null;
            if (!is_array($textBlock)) {
                // Same shape the picker produces, so templates see no difference.
                $textBlock = [
                    'title' => null,
                    'description' => null,
                    'rights' => null,
                    'credits' => null,
                    'tags' => null,
                    'altText' => null,
                    'categories' => null,
                    'documentinfo' => null,
                ];
            }

            foreach ($fieldMap as $pickerKey => $apiKey) {
                if (array_key_exists($apiKey, $apiDoc)) {
                    $textBlock[$pickerKey] = $apiDoc[$apiKey];
                }
            }

            $mapped['text'][$lang] = $textBlock;
        }

        if ($apiChanged !== null) {
            $mapped['sync'] = ['changed' => $apiChanged];
        }

        return $mapped;
    }

    /**
     * Fetches the latest metadata for changed documents into a new run
     * snapshot. The run's watermark advances once `updateImages()` has queued
     * its jobs and they have completed, so the pair still forms a complete
     * two-phase sync.
     *
     * @deprecated in 3.3.0. Use `ImageShop::getInstance()->sync->run()`.
     **/
    public function updateRecentlyUpdatedCache(): void
    {
        Plugin::getInstance()->sync->updateRecentlyUpdatedCache();
    }

    /**
     * Returns the ids of documents Imageshop reports as changed since the
     * last successful sync run, or null when the API could not be asked.
     *
     * The distinction matters: an empty array is a genuine "nothing changed"
     * and lets the sync window advance; null must leave it where it is.
     *
     * @return int[]|null
     **/
    public function getRecentlyUpdatedDocumentIds(): ?array
    {
        $lastUpdate = $this->getDateLastUpdated();
        $response = $this->_request('GET','/Document/GetAllDocumentIdsChangedAfter',[
            'query' => [
                'changed' => $lastUpdate
                ]
            ]);

        if (!is_string($response)) {
            return null;
        }

        $ids = Json::decodeIfJson($response);

        return is_array($ids) ? array_map('intval', $ids) : null;
    }

    /**
     * Queues sync jobs for every element referencing the latest run's cache.
     * The run's watermark advances when the last of those jobs completes.
     *
     * @deprecated in 3.3.0. Use `ImageShop::getInstance()->sync->run()`.
     * @return int Number of queue jobs created
     **/
    public function updateImages(): int
    {
        $runId = $this->getLatestSyncRunId();
        if (!$runId) {
            return 0;
        }

        return Plugin::getInstance()->sync->queueSyncJobs($this->getDocumentCache($runId), $runId);
    }

    /**
     * Builds a summary of synced documents from the document cache.
     *
     * @param array $documentCache The document cache keyed by document ID
     * @return array Array of ['documentId' => int, 'name' => string]
     **/
    public function buildSyncDetails(array $documentCache): array
    {
        $details = [];
        foreach ($documentCache as $documentId => $langData) {
            $name = null;
            if (is_array($langData)) {
                foreach ($langData as $doc) {
                    if (is_array($doc) && !empty($doc['Name'])) {
                        $name = $doc['Name'];
                        break;
                    }
                }
            }
            $details[] = [
                'documentId' => (int)$documentId,
                'name' => $name ?? "Document {$documentId}",
            ];
        }
        return $details;
    }

    /**
     * Logs a sync run to the sync log table.
     *
     * @param int $documentsChanged Number of documents fetched from API
     * @param int $jobsQueued Number of queue jobs created
     * @param string $status 'success' or 'no_changes'
     **/
    public function logSync(int $documentsChanged, int $jobsQueued, string $status, array $details = []): void
    {
        try {
            Craft::$app->getDb()
                ->createCommand()
                ->insert('{{%imageshop-dam_sync_log}}', [
                    'dateCreated' => Db::prepareDateForDb(new \DateTime()),
                    'documentsChanged' => $documentsChanged,
                    'jobsQueued' => $jobsQueued,
                    'status' => $status,
                    'details' => !empty($details) ? Json::encode($details) : null,
                ])
                ->execute();

            // Prune old entries, keeping the most recent 20
            $cutoffId = (new Query())
                ->select('id')
                ->from('{{%imageshop-dam_sync_log}}')
                ->orderBy(['id' => SORT_DESC])
                ->offset(20)
                ->limit(1)
                ->scalar();

            if ($cutoffId) {
                Craft::$app->getDb()
                    ->createCommand()
                    ->delete('{{%imageshop-dam_sync_log}}', ['<=', 'id', $cutoffId])
                    ->execute();
            }
        } catch (\yii\db\Exception $e) {
            Craft::warning('Could not write to sync log table: ' . $e->getMessage(), 'imageshop-dam');
        }
    }

    /**
     * Returns recent sync log entries.
     *
     * @param int $limit Max entries to return
     * @return array
     **/
    public function getSyncLog(int $limit = 10): array
    {
        try {
            $rows = (new Query())
                ->select(['dateCreated', 'documentsChanged', 'jobsQueued', 'status', 'details'])
                ->from('{{%imageshop-dam_sync_log}}')
                ->orderBy(['dateCreated' => SORT_DESC])
                ->limit($limit)
                ->all();

            foreach ($rows as &$row) {
                $row['details'] = $row['details'] ? Json::decodeIfJson($row['details']) : null;
            }
            unset($row);

            return $rows;
        } catch (\yii\db\Exception $e) {
            return [];
        }
    }

    /**
     * sometimes the language code doesn't match with the API, this tries to match the relevant one, or any.
     *
     * @param string $lang Language
     * @return string Sanitized language
     **/
    public function sanitizeLanguage(string $lang = null): ?string
    {
        switch ($lang) {
            case 'nb-NO':
                $lang = 'no';
                break;

            default:
                $lang = \Locale::getPrimaryLanguage($lang);
                break;
        }
        return $lang;
    }

    /**
     * Resolves the Imageshop language code for a given Craft site.
     * Checks the per-site mapping configured in plugin settings first; falls back to
     * sanitizing the site's Craft language; finally falls back to the global default.
     *
     * @param \craft\models\Site|null $site
     * @return string
     **/
    public function getImageshopLanguageForSite(?\craft\models\Site $site): string
    {
        $settings = Plugin::$plugin->getSettings();

        // Every code returned here is canonicalized so it matches the keys
        // used in the stored `text` / `overrides` blocks and the keys an
        // explicit getter argument is normalized to. A mapping of `en-US`
        // would otherwise write overrides under one key and read another.
        if ($site) {
            $mapped = $settings->siteLanguages[$site->handle] ?? null;
            if (is_string($mapped) && $mapped !== '') {
                return $this->sanitizeLanguage($mapped) ?: $mapped;
            }
            $sanitized = $this->sanitizeLanguage($site->language);
            if ($sanitized) {
                return $sanitized;
            }
        }

        $fallback = (string)$settings->language;

        return $this->sanitizeLanguage($fallback) ?: $fallback;
    }

    /**
     * How many sync run snapshots to keep. Queued jobs read the snapshot of
     * the run that created them, so this should outlast any realistic queue
     * backlog (100 runs is a day at a 15-minute cron). A job whose snapshot
     * is gone anyway refetches its documents from the API, so pruning can
     * delay a job but not lose an update.
     */
    private const SYNC_RUNS_TO_KEEP = 100;

    /**
     * Returns the document cache of one sync run, or of the latest run.
     *
     * Each run stores its own snapshot (one row in `imageshop-dam_sync`), so
     * jobs still waiting in the queue keep reading the data they were queued
     * for even after a later run has fetched something else.
     *
     * @param int|null $runId The run to read, or null for the most recent one
     * @return array [documentId => [lang => apiDoc]]
     **/
    public function getDocumentCache(?int $runId = null): array
    {
        $query = (new Query())
            ->select('documentCache')
            ->from('{{%imageshop-dam_sync}}');

        if ($runId !== null) {
            $query->where(['id' => $runId]);
        } else {
            $query->orderBy(['id' => SORT_DESC]);
        }

        $row = $query->one();

        if (empty($row)) {
            return [];
        }

        $decoded = Json::decodeIfJson($row['documentCache']);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Stores a sync run's document cache as a new snapshot and returns the run id.
     *
     * `$lastUpdated` is the sync watermark this run vouches for right now: the
     * next run asks Imageshop for documents changed after the newest watermark
     * on record. `$watermark` is the one the run will vouch for once all of
     * its queued jobs have completed (see `completeSyncJob()`); pass the same
     * value as `$lastUpdated` for a run that must not advance the window.
     *
     * @param array $documentCache [documentId => [lang => apiDoc]]
     * @param string|null $lastUpdated DB-formatted timestamp, or null for now
     * @param string|null $watermark DB-formatted timestamp to advance to on completion, or null for none
     * @return int The run id
     **/
    public function setDocumentCache(array $documentCache, ?string $lastUpdated = null, ?string $watermark = null): int
    {
        $db = Craft::$app->getDb();
        $table = '{{%imageshop-dam_sync}}';

        $db->createCommand()
            ->insert($table, [
                'lastUpdated' => $lastUpdated ?? Db::prepareDateForDb(new \DateTime()),
                'watermark' => $watermark,
                'documentCache' => Json::encode($documentCache),
            ])
            ->execute();

        // Passing the table name is Craft's convention and is portable:
        // craft\db\pgsql\Schema::getLastInsertID() resolves it to the
        // `<schema>.<table>_id_seq` sequence, and MySQL ignores the argument.
        // Craft core does the same (e.g. Drafts::insertDraftRow()).
        $runId = (int)$db->getLastInsertID($table);

        $cutoffId = (new Query())
            ->select('id')
            ->from($table)
            ->orderBy(['id' => SORT_DESC])
            ->offset(self::SYNC_RUNS_TO_KEEP)
            ->limit(1)
            ->scalar();

        if ($cutoffId) {
            $db->createCommand()->delete($table, ['<=', 'id', $cutoffId])->execute();
        }

        return $runId;
    }

    /**
     * Advances the watermark a stored run vouches for.
     *
     * A run is first stored with the previous watermark, its work is done
     * (jobs queued or elements saved), and only then is the watermark moved
     * forward. If anything dies in between, the next run asks Imageshop for
     * the same changes again; duplicate work is idempotent, lost work is not.
     *
     * @param int $runId
     * @param string $watermark DB-formatted timestamp
     **/
    public function advanceSyncWatermark(int $runId, string $watermark): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->update('{{%imageshop-dam_sync}}', ['lastUpdated' => $watermark], ['id' => $runId])
            ->execute();
    }

    /**
     * Records how many jobs a run queued. Call this before pushing them, so a
     * job that finishes quickly cannot see a count of zero and complete the
     * run early. A run that queued nothing completes immediately.
     **/
    public function setSyncRunJobs(int $runId, int $jobsQueued): void
    {
        Craft::$app->getDb()
            ->createCommand()
            ->update('{{%imageshop-dam_sync}}', ['jobsQueued' => $jobsQueued, 'jobsCompleted' => 0, 'jobsFailed' => 0], ['id' => $runId])
            ->execute();

        if ($jobsQueued === 0) {
            $this->completeSyncRunIfDone($runId);
        }
    }

    /**
     * Records the outcome of one queued job and advances the run's watermark
     * once every job it queued has succeeded.
     *
     * A failed job is counted but never completes the run, so its watermark
     * stays put and the next sync run asks Imageshop for the same changes
     * again and queues the element afresh. If the failed job is retried from
     * the queue and succeeds, it counts as completed then.
     **/
    public function completeSyncJob(int $runId, bool $success): void
    {
        $column = $success ? 'jobsCompleted' : 'jobsFailed';

        Craft::$app->getDb()
            ->createCommand()
            ->update('{{%imageshop-dam_sync}}', [$column => new \yii\db\Expression("[[{$column}]] + 1")], ['id' => $runId])
            ->execute();

        if ($success) {
            $this->completeSyncRunIfDone($runId);
        }
    }

    /**
     * Advances a run's watermark if all of its queued jobs have completed.
     **/
    public function completeSyncRunIfDone(int $runId): void
    {
        $row = (new Query())
            ->select(['watermark', 'lastUpdated', 'jobsQueued', 'jobsCompleted'])
            ->from('{{%imageshop-dam_sync}}')
            ->where(['id' => $runId])
            ->one();

        if (!$row || empty($row['watermark']) || $row['watermark'] === $row['lastUpdated']) {
            return;
        }

        if ((int)$row['jobsCompleted'] >= (int)$row['jobsQueued']) {
            $this->advanceSyncWatermark($runId, $row['watermark']);
        }
    }

    /**
     * Returns the id of the most recent sync run, if any.
     **/
    public function getLatestSyncRunId(): ?int
    {
        $id = (new Query())
            ->select('id')
            ->from('{{%imageshop-dam_sync}}')
            ->orderBy(['id' => SORT_DESC])
            ->scalar();

        return $id ? (int)$id : null;
    }

    /**
     * Returns the sync watermark: the newest timestamp any run has vouched
     * for. The next run asks Imageshop for documents changed after it.
     *
     * @return string DB-formatted timestamp
     **/
    public function getDateLastUpdated(): string
    {
        $latest = (new Query())
            ->select(['max' => 'MAX([[lastUpdated]])'])
            ->from('{{%imageshop-dam_sync}}')
            ->scalar();

        return $latest ?: '2000-01-01 00:00:00';
    }

    /**
     * Gets a permanent CDN URL for a document at a specific size, creating it
     * via the API only if we have never created it before.
     *
     * Permalinks are stored in the database rather than the cache. This matters:
     * /Permalink/CreatePermaLinkFromDocumentId is not idempotent, so every call
     * mints a brand new permalink. Backing this with a cache meant that any
     * cache flush — a deploy, a container restart, or a second app node with its
     * own file cache — silently created a new URL that CloudFront had never
     * seen, costing several seconds on the next page load.
     *
     * A cache miss must therefore never be able to reach the API. The only
     * thing cached here is *failure*, as a short back-off.
     *
     * Stored permalinks never expire. That is deliberate, and it rests on
     * Imageshop confirming that a permalink keeps resolving to the current
     * image when that image is replaced under the same documentId — so there
     * is nothing to invalidate. Do not add a max age here without revisiting
     * that: an expiry would reintroduce exactly the churn this replaced, just
     * on a slower cycle. `php craft imageshop-dam/permalinks/clear` exists as
     * the manual escape hatch if a permalink ever does need recreating.
     *
     * @param int $documentId Document Id
     * @param int $width Desired width (0 for auto)
     * @param int $height Desired height (0 for auto)
     * @return ?string The permanent image URL
     **/
    public function getCachedPermalink(int $documentId, int $width = 0, int $height = 0): ?string
    {
        if ($documentId <= 0) {
            return null;
        }

        // Guard against absurd dimensions creating junk rows. The public
        // permalink action takes width/height straight from query params.
        $width = max(0, min($width, self::PERMALINK_MAX_DIMENSION));
        $height = max(0, min($height, self::PERMALINK_MAX_DIMENSION));

        $sizeKey = $width . 'x' . $height;

        // 1. Durable store. One query per document per request covers every
        //    size, so a getSrcset() call spanning nine widths is a single read.
        $stored = $this->_loadPermalinksForDocument($documentId);
        if (isset($stored[$sizeKey])) {
            return $stored[$sizeKey];
        }

        // 2. Back off if the API just failed for this derivative, so an
        //    Imageshop outage cannot turn every render into N failing calls.
        $failureKey = self::PERMALINK_FAILURE_PREFIX . $documentId . '_' . $sizeKey;
        if (Craft::$app->getCache()->get($failureKey) !== false) {
            return null;
        }

        // 3. Mint one. This is the only path that calls the API, and for a
        //    given document and size it should run exactly once per install.
        $url = $this->getPermalink($documentId, $width, $height);

        if ($url === null) {
            Craft::$app->getCache()->set($failureKey, true, self::PERMALINK_FAILURE_TTL);
            return null;
        }

        $url = $this->_storePermalink($documentId, $width, $height, $url);
        $this->_permalinkMemo[$documentId][$sizeKey] = $url;

        return $url;
    }

    /**
     * Deletes stored permalinks, forcing them to be recreated on next request.
     *
     * @param int|null $documentId Limit to one document, or null for all
     * @return int Number of rows deleted
     **/
    public function clearPermalinks(?int $documentId = null): int
    {
        try {
            $deleted = Craft::$app->getDb()
                ->createCommand()
                ->delete(self::PERMALINK_TABLE, $documentId !== null ? ['documentId' => $documentId] : '')
                ->execute();
        } catch (\yii\db\Exception $e) {
            Craft::warning('Could not clear permalinks: ' . $e->getMessage(), 'imageshop-dam');
            return 0;
        }

        if ($documentId !== null) {
            unset($this->_permalinkMemo[$documentId]);
        } else {
            $this->_permalinkMemo = [];
        }

        return $deleted;
    }

    /**
     * Loads every stored permalink for a document, memoized per request.
     *
     * @param int $documentId Document Id
     * @return array Map of "{width}x{height}" => url
     **/
    private function _loadPermalinksForDocument(int $documentId): array
    {
        if (array_key_exists($documentId, $this->_permalinkMemo)) {
            return $this->_permalinkMemo[$documentId];
        }

        $permalinks = [];

        try {
            $rows = (new Query())
                ->select(['width', 'height', 'url'])
                ->from(self::PERMALINK_TABLE)
                ->where(['documentId' => $documentId])
                ->all();

            foreach ($rows as $row) {
                // Never hand back a stored value that is not a URL, however it
                // got there. A skipped row is re-minted on demand, guarded by
                // the failure back-off.
                if ($this->_isPermalinkUrl($row['url'])) {
                    $permalinks[$row['width'] . 'x' . $row['height']] = $row['url'];
                }
            }
        } catch (\yii\db\Exception $e) {
            // Most likely the migration has not run yet. Fall through to the
            // API rather than taking image rendering down entirely.
            Craft::warning('Could not read permalink table: ' . $e->getMessage(), 'imageshop-dam');
        }

        $this->_permalinkMemo[$documentId] = $permalinks;

        return $permalinks;
    }

    /**
     * Stores a newly minted permalink, keeping whichever one landed first.
     *
     * Two app nodes can race and each mint a permalink for the same derivative.
     * Both URLs resolve to the same image, so rather than letting the later
     * writer overwrite, the insert is a no-op on conflict and the stored value
     * wins. Every node then converges on one URL and CloudFront stays warm.
     *
     * @return string The canonical stored URL, or the passed URL if it could not be stored
     **/
    private function _storePermalink(int $documentId, int $width, int $height, string $url): string
    {
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            Craft::$app->getDb()
                ->createCommand()
                ->upsert(self::PERMALINK_TABLE, [
                    'documentId' => $documentId,
                    'width' => $width,
                    'height' => $height,
                    'url' => $url,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                ], false)
                ->execute();

            $canonical = (new Query())
                ->select(['url'])
                ->from(self::PERMALINK_TABLE)
                ->where([
                    'documentId' => $documentId,
                    'width' => $width,
                    'height' => $height,
                ])
                ->scalar();

            if (is_string($canonical) && $canonical !== '') {
                return $canonical;
            }
        } catch (\yii\db\Exception $e) {
            Craft::warning('Could not store permalink: ' . $e->getMessage(), 'imageshop-dam');
        }

        return $url;
    }

    /**
     * Gets a permanent CDN URL for a document at a specific size.
     *
     * @param int $documentId Document Id
     * @param int $width Desired width (0 for auto)
     * @param int $height Desired height (0 for auto)
     * @return ?string The permanent image URL
     **/
    public function getPermalink(int $documentId, int $width = 0, int $height = 0): ?string
    {
        $response = $this->_request('GET', '/Permalink/CreatePermaLinkFromDocumentId', [
            'query' => [
                'documentid' => $documentId,
                'width' => $width,
                'height' => $height,
            ]
        ]);

        if (!$response || !Json::isJsonObject($response)) {
            return null;
        }

        $data = Json::decode($response);

        // For a deleted or inaccessible document the API answers 200 with a
        // JSON body whose `url` is the literal string "Access denied". The
        // isJsonObject() guard above passes, so the value has to be validated
        // as a URL or it ends up rendered as <img src="Access denied">.
        // See imageshoporg/Craft#14.
        return $this->_isPermalinkUrl($data['url'] ?? null) ? $data['url'] : null;
    }

    /**
     * Whether a value returned by, or stored for, the permalink API is actually
     * a usable URL rather than an error string.
     **/
    private function _isPermalinkUrl(mixed $url): bool
    {
        return is_string($url) && preg_match('~^https?://~i', $url) === 1;
    }

    /**
     * base api call helper
     *
     * @param string $method default GET
     * @param string $action The target endpoint
     * @param string $params Params to be included in the call
     * @return mixed
     **/
    private function _request(string $method='GET', string $action='', array $params=[]): mixed
    {
        $settings = Plugin::$plugin->getSettings();
        // If no token is set in settings, skip the call.
        // privateKey is optional per the ImageShop API; auth is via the Token header.
        if (empty($settings->token)) {
            return null;
        }

        $client = new Client([
            'base_uri' => 'https://api.imageshop.no',
            // Without these Guzzle waits on PHP's default_socket_timeout (60s).
            // A slow API would otherwise stall a page render for a minute per image.
            'connect_timeout' => 5,
            'timeout' => 10,
            // Handle status codes ourselves; with Guzzle's default a 404
            // throws before the status branches below can run.
            'http_errors' => false,
            'headers' => [
                'Token' => App::parseEnv($settings->token),
                'Accept' => 'application/json',
                'Content-Type' => 'application/xml'
            ]
        ]);

        try {
            $response = $client->request($method, $action, $params);
        } catch (GuzzleException $e) {
            // Transport failure: the caller must be able to tell this apart
            // from "nothing there", or a sync run during an outage would be
            // recorded as a successful empty one.
            Craft::error('Imageshop API request failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }

        $status = $response->getStatusCode();

        if ($status == 200) {
            return $response->getBody()->getContents();
        }

        if ($status == 404) {
            return null;
        }

        Craft::error("Imageshop API request to {$action} returned HTTP {$status}", __METHOD__);
        return false;
    }

}
