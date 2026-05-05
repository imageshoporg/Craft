<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

namespace webdna\imageshop\services;

use webdna\imageshop\fields\ImageShopField;
use webdna\imageshop\ImageShop as Plugin;
use webdna\imageshop\jobs\Sync;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * @author    WebDNA
 * @package   Imageshop
 * @since     2.0.0
 */
class ImageShop extends Component
{
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

        if (empty($settings->token) || empty($settings->key)) {
            return null;
        }

        $response = $this->_request('GET','/Login/GetTemporaryToken',[
            'query' => [
                'privateKey' => App::parseEnv($settings->key)
            ]
        ]);

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
     * @return ?array document data
     **/
    public function getDocumentById(int $documentId, string $language): ?array
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

        if (!Json::isJsonObject($response)) {
            return null;
        }

        return Json::decode($response);
    }

    /**
     * gets all the imageshop field column names for the content table
     *
     * @return array an array of column names
     **/
    public function getImageShopFields(): array
    {
        $imageShopFields = Craft::$app->getFields()->getFieldsByType(ImageShopField::class);
        $fields = [];
        foreach ($imageShopFields as $field) {
            $columnName = '';
            if ($field->columnPrefix) {
                $columnName .= $field->columnPrefix . '_';
            }
            $columnName .= 'field_' . $field->handle;
            if ($field->columnSuffix) {
                $columnName .= '_' . $field->columnSuffix;
            }
            $fields[] = $columnName;
        }

        return $fields;
    }

    /**
     * Get all rows from the content table that contain an imageshop field value
     * and format them into format with keys: 'rowId','rowUid','documentIds','fields' (contains all document data)
     *
     * @return array
     **/
    public function getAllImageShopContentRows(): array
    {
        $fields = $this->getImageShopFields();

        $rowsQuery = (new Query())
            ->select('*')
            ->from(Table::CONTENT);

        // Use OR so rows with ANY ImageShop field populated are included
        $condition = ['or'];
        foreach ($fields as $field) {
            $condition[] = ['not', [$field => null]];
        }
        $rowsQuery->andWhere($condition);
        // would be better to do this with something like JSON_CONTAINS but
        // can't be certain about db driver or version on system.

        $rows = [];
        foreach ($rowsQuery->each() as $value) {
            $row = [
                'rowId' => $value['id'],
                'rowUid' => $value['uid'],
                'documentIds' => [],
                'fields' => []
            ];

            foreach ($fields as $field) {
                if (array_key_exists($field,$value) && is_string($value[$field]) && Json::isJsonObject($value[$field])) {
                    $fieldValue = Json::decode($value[$field]);
                    // deal with pre-allow multiple update
                    if (array_key_exists('documentId', $fieldValue)) {
                       $fieldValue = [$fieldValue];
                    }

                    foreach ($fieldValue as $v) {
                        $imageData = is_array($v) ? $v : Json::decodeIfJson($v);
                        $row['documentIds'][] = $imageData['documentId'];
                        $row['fields'][$field][($imageData['documentId'])] = $imageData;
                    }
                }
            }
            $rows[] = $row;
        }
        return $rows;

    }

    /**
     * Updates the content of an imageshop field with data from the
     * recently updated cache
     *
     * @param array $config Details of the row to be updated, must contain 'rowId','rowUid' and 'documentId'
     **/
    public function updateContentRow(array $config): void
    {
        $fieldColumnNames = $this->getImageShopFields();
        $updatedDocuments = $this->getDocumentCache();

        $rowsQuery = (new Query())
            ->select($fieldColumnNames)
            ->from(Table::CONTENT)
            ->where([
                'id' => $config['rowId'],
                'uid' => $config['rowUid']
            ])
            ->one();

        if (!$rowsQuery) {
            return;
        }

        $newData = [];
        foreach ($fieldColumnNames as $columnName) {
            if ($rowsQuery[$columnName] === null) {
                continue;
            }
            $oldData = Json::decodeIfJson($rowsQuery[$columnName]);
            $newData[$columnName] = [];
            if (is_array($oldData)) {
                if (array_key_exists('documentId', $oldData)) {
                    $oldData = [$oldData];
                }
                foreach ($oldData as $documentJson) {
                    $document = Json::decodeIfJson($documentJson);
                    if (array_key_exists($document['documentId'], $updatedDocuments)) {
                        $newData[$columnName][] = $this->mapDocumentFields($document,$updatedDocuments[$document['documentId']]);
                    } else {
                        $newData[$columnName][] = $document;
                    }
                }
                $newData[$columnName] = Json::encode($newData[$columnName]);
            } else {
                $newData[$columnName] = Json::encode($oldData);
            }
        }

        Craft::$app->getDb()
            ->createCommand()
            ->update(
                Table::CONTENT,
                $newData,
                [
                    'id' => $config['rowId'],
                    'uid' => $config['rowUid']
                ]
            )
            ->execute();

        return;
    }

    /**
     * Maps API data for sync to the stored field values
     *
     * @param array $dataFromPicker Data model that comes from the imageshop image picker pop up
     * @param array $dataFromApi Data model that comes from API during sync
     * @return array $mapped The updated data in the form of the picker data
     **/
    public function mapDocumentFields(array $dataFromPicker, array $dataFromApi): array
    {
        $mapped = $dataFromPicker;

        // The cache stores per-language API responses: { lang => apiDoc }
        // Each apiDoc has top-level fields: AltText, Description, Credits, etc.
        $fieldMap = [
            'altText' => 'AltText',
            'description' => 'Description',
            'title' => 'Name',
            'credits' => 'Credits',
            'rights' => 'Rights',
            'tags' => 'Tags',
        ];

        if (isset($mapped['text']) && is_array($mapped['text'])) {
            foreach ($mapped['text'] as $lang => &$textBlock) {
                if (!isset($dataFromApi[$lang]) || !is_array($dataFromApi[$lang])) {
                    continue;
                }
                $apiDoc = $dataFromApi[$lang];
                foreach ($fieldMap as $pickerKey => $apiKey) {
                    if (array_key_exists($apiKey, $apiDoc)) {
                        $textBlock[$pickerKey] = $apiDoc[$apiKey];
                    }
                }
            }
            unset($textBlock);
        }

        return $mapped;
    }

    /**
     * Updates the recently updated dump in the db
     *
     * @return void
     **/
    public function updateRecentlyUpdatedCache(): void
    {
        $recentlyUpdatedIds = $this->_getRecentlyUpdated();
        $imageShopDbRows = $this->getAllImageShopContentRows();
        $this->_getNewImageData($imageShopDbRows, $recentlyUpdatedIds);
    }


    /**
     * Creates the recently updated document cache using the getDocumentById API call.
     * Fetches each document once per language found in the picker data so that
     * all language-specific text fields can be synced.
     *
     * Cache format: { documentId => { lang => apiResponse, ... }, ... }
     *
     * @param array $dbRows data from content table row in the format 'rowId','rowUid','documentIds','fields' (contains all document data)
     * @param array $recentlyUpdatedIds Document Ids from the recently updated api call
     **/
    private function _getNewImageData(array $dbRows, $recentlyUpdatedIds): void
    {
        $documentCache = [];
        $documentIds = $this->_getDocumentIdsFromImages($dbRows);
        $forUpdate = array_intersect($documentIds, $recentlyUpdatedIds);

        if (count($forUpdate) > 0) {
            // Collect all languages present in the picker data
            $languages = $this->_getLanguagesFromContentRows($dbRows);

            foreach ($forUpdate as $documentId) {
                $documentCache[$documentId] = [];
                foreach ($languages as $lang) {
                    $doc = $this->getDocumentById($documentId, $lang);
                    if ($doc) {
                        $documentCache[$documentId][$lang] = $doc;
                    }
                }
            }
        }

        // Always update cache and bump lastUpdated timestamp,
        // even when empty, to clear stale data and prevent re-fetching
        $this->_setDocumentCache($documentCache);
    }

    /**
     * Extracts all unique sanitized language codes from the picker text data
     * across all content rows.
     *
     * @param array $rows Content rows from getAllImageShopContentRows
     * @return array Unique language codes
     **/
    private function _getLanguagesFromContentRows(array $rows): array
    {
        $languages = [];
        foreach ($rows as $row) {
            foreach ($row['fields'] as $docs) {
                foreach ($docs as $data) {
                    if (isset($data['text']) && is_array($data['text'])) {
                        foreach (array_keys($data['text']) as $lang) {
                            $languages[$lang] = true;
                        }
                    }
                }
            }
        }
        return array_keys($languages);
    }

    /**
     * Takes the formatted content table rows and returns all the documentIds in the whole site.
     *
     * @param array $images Content rows
     * @return array Just the DocumentIds
     **/
    private function _getDocumentIdsFromImages(array $images): array
    {
        $columns = array_column($images, 'documentIds');
        if (empty($columns)) {
            return [];
        }
        return array_unique(array_merge(...$columns));
    }

    /**
     * gets the recently updated documents from the imageshop API using the last time the
     * update was run as the date.
     *
     * @return array Array of DocumentIds
     **/
    private function _getRecentlyUpdated(): array
    {
        $lastUpdate = $this->_getDateLastUpdated();
        $response = $this->_request('GET','/Document/GetAllDocumentIdsChangedAfter',[
            'query' => [
                'changed' => $lastUpdate
                ]
            ]);
        $ids = Json::decodeIfJson($response);

        return $ids ?? [];
    }

    /**
     * Creates the queue jobs to update all the relevant content rows in the db with the latest imageshop image data.
     *
     * @return int Number of queue jobs created
     **/
    public function updateImages(): int
    {
        $documentCache = $this->getDocumentCache();

        if (count($documentCache) === 0) {
            return 0;
        }

        $cachedDocumentIds = array_keys($documentCache);
        $contentRows = $this->getAllImageShopContentRows();

        // Only create jobs for rows that contain documents that actually changed
        $affectedRows = array_filter($contentRows, function ($row) use ($cachedDocumentIds) {
            return !empty(array_intersect($row['documentIds'], $cachedDocumentIds));
        });

        $index = 0;
        $total = count($affectedRows);

        foreach ($affectedRows as $row) {
            Craft::$app->getQueue()->ttr(3600)->push(new Sync([
                'rowId' => $row['rowId'],
                'rowUid' => $row['rowUid'],
                'documentIds' => Json::encode($row['documentIds']),
                'fields' => Json::encode($row['fields']),
                'index' => $index++,
                'count' => $total
            ]));
        }

        return $total;
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

        if ($site) {
            $mapped = $settings->siteLanguages[$site->handle] ?? null;
            if (is_string($mapped) && $mapped !== '') {
                return $mapped;
            }
            $sanitized = $this->sanitizeLanguage($site->language);
            if ($sanitized) {
                return $sanitized;
            }
        }

        return $settings->language;
    }

    /**
     * Gets the recently updated document cache from the db
     *
     * @return array The document cache
     **/
    public function getDocumentCache(): array
    {
        $query = (new Query())
            ->select('documentCache')
            ->from('{{%imageshop-dam_sync}}')
            ->orderBy(['lastUpdated' => SORT_DESC])
            ->one();

        if (empty($query)) {
            return [];
        }

        $decoded = Json::decodeIfJson($query['documentCache']);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * writes the new document cache to the db
     *
     * @param array $documentCache The new document data
     * @return bool
     **/
    private function _setDocumentCache(array $documentCache): bool
    {
        $lastUpdate = Db::prepareDateForDb(new \DateTime());
        Craft::$app->getDb()
            ->createCommand()
            ->upsert('{{%imageshop-dam_sync}}', [
                'id' => 1,
                'lastUpdated' => $lastUpdate,
                'documentCache' => Json::encode($documentCache)
            ], [
                'lastUpdated' => $lastUpdate,
                'documentCache' => Json::encode($documentCache)
            ])
            ->execute();

        return true;
    }

    /**
     * Gets the last time the update was ran
     *
     * @return string The lastest date updated
     **/
    private function _getDateLastUpdated(): string
    {
        $query = (new Query())
            ->select('lastUpdated')
            ->from('{{%imageshop-dam_sync}}')
            ->orderBy(['lastUpdated' => SORT_DESC])
            ->one();

        return $query['lastUpdated'] ?? '2000-01-01 00:00:00';
    }

    /**
     * Gets a cached permanent CDN URL for a document at a specific size.
     *
     * @param int $documentId Document Id
     * @param int $width Desired width (0 for auto)
     * @param int $height Desired height (0 for auto)
     * @return ?string The permanent image URL
     **/
    public function getCachedPermalink(int $documentId, int $width = 0, int $height = 0): ?string
    {
        $cacheKey = "imageshop_permalink_{$documentId}_{$width}_{$height}";

        $url = Craft::$app->getCache()->getOrSet($cacheKey, function () use ($documentId, $width, $height) {
            return $this->getPermalink($documentId, $width, $height) ?? false;
        }, 60 * 60 * 24 * 30); // 30 days

        return $url ?: null;
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
        return $data['url'] ?? null;
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
        // If no token is sent or set in settings
        if (empty($settings->token) || empty($settings->key)) {
            return null;
        }

        $client = new Client([
            'base_uri' => 'https://api.imageshop.no',
            'headers' => [
                'Token' => App::parseEnv($settings->token),
                'Accept' => 'application/json',
                'Content-Type' => 'application/xml'
            ]
        ]);

        try {
            $response = $client->request($method, $action, $params);
        } catch (GuzzleException $e) {
            Craft::error('Imageshop API request failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }

        if ($response->getStatusCode() == 200) {
            return $response->getBody()->getContents();
        }

        return null;
    }

}
