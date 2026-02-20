<?php
/**
 * ImageShop plugin for Craft CMS 3.x
 *
 * ImageShop Integration for CraftCMS
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
 * @package   ImageShop
 * @since     2.0.0
 */
class ImageShop extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Get a temporary access token
     *
     * @return mixed
     **/
    public function getTemporaryToken(): mixed
    {
        $settings = Plugin::$plugin->getSettings();

        // If no token is sent or set in settings
        if (empty($settings->token) || empty($settings->key)) {
            return null;
        }
        return $this->_request('GET','/Login/GetTemporaryToken',[
            'query' => [
                'privateKey' => App::parseEnv($settings->key)
            ]
        ]);
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


        foreach ($fields as $field) {
            $rowsQuery->andWhere(['not', [$field => null]]);
        }
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
                if (array_key_exists($field,$value) && Json::isJsonObject($value[$field])) {
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

        // Build a lookup of API text data keyed by language
        $apiTextByLang = [];
        if (isset($dataFromApi['InterfaceList']) && is_array($dataFromApi['InterfaceList'])) {
            foreach ($dataFromApi['InterfaceList'] as $iface) {
                $lang = $this->sanitizeLanguage($iface['Language'] ?? '');
                if ($lang) {
                    $apiTextByLang[$lang] = $iface;
                }
            }
        }

        // Sync all text fields across all languages present in the picker data
        if (isset($mapped['text']) && is_array($mapped['text'])) {
            foreach ($mapped['text'] as $lang => &$textBlock) {
                if (!isset($apiTextByLang[$lang])) {
                    continue;
                }
                $apiText = $apiTextByLang[$lang];
                $fieldMap = [
                    'altText' => 'AltText',
                    'description' => 'Description',
                    'title' => 'Title',
                    'credits' => 'Credits',
                    'rights' => 'Rights',
                    'tags' => 'Tags',
                ];
                foreach ($fieldMap as $pickerKey => $apiKey) {
                    if (isset($apiText[$apiKey])) {
                        $textBlock[$pickerKey] = $apiText[$apiKey];
                    }
                }
            }
            unset($textBlock);
        }

        // Also check flat altText for backwards compatibility
        if (isset($dataFromApi['altText']) && isset($mapped['text'])) {
            $settings = Plugin::getInstance()->getSettings();
            $language = $this->sanitizeLanguage($settings->language);
            if (isset($mapped['text'][$language]) && !isset($apiTextByLang[$language])) {
                $mapped['text'][$language]['altText'] = $dataFromApi['altText'];
            }
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
     * Creates the recently updated document cache using the getDocumentById API call
     *
     * @param array $dbRows data from content table row in the format 'rowId','rowUid','documentIds','fields' (contains all document data)
     * @param array $recentlyUpdatedIds Document Ids from the recently updated api call
     **/
    private function _getNewImageData(array $dbRows, $recentlyUpdatedIds): void
    {
        $settings = Plugin::$plugin->getSettings();
        $documentCache = [];
        $documentIds = $this->_getDocumentIdsFromImages($dbRows);
        $forUpdate = array_intersect($documentIds, $recentlyUpdatedIds);

        if (count($forUpdate) === 0) {
            return;
        }

        foreach ($forUpdate as $documentId) {
            $documentCache[$documentId] = $this->getDocumentById($documentId,$settings->language);
        }


        $this->_setDocumentCache($documentCache);

        return;
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
     * Creates the queue jobs to update all the relevent content rows in the db with the latest imageshop image data
     **/
    public function updateImages(): void
    {
        // check if there is anything to do
        $documentCache = $this->getDocumentCache();

        if (count($documentCache) == 0) {
            return;
        }

        $contentRows = $this->getAllImageShopContentRows();
        $index = 0;
        $total = count($contentRows);

        foreach ($contentRows as $row) {
            Craft::$app->getQueue()->ttr(3600)->push(new Sync([
                'rowId' => $row['rowId'],
                'rowUid' => $row['rowUid'],
                'documentIds' => Json::encode($row['documentIds']),
                'fields' => Json::encode($row['fields']),
                'index' => $index++,
                'count' => $total
            ]));
        }
        return;
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
     * Gets the recently updated document cache from the db
     *
     * @return array|string The document cache
     **/
    public function getDocumentCache(): array|string
    {
        $query = (new Query())
            ->select('documentCache')
            ->from('{{%imageshop-dam_sync}}')
            ->orderBy(['lastUpdated' => SORT_DESC])
            ->one();

        if (empty($query)) {
            return [];
        }

        return Json::decodeIfJson($query['documentCache']);
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

        return $query['lastUpdated'] ?? (new \DateTime('2000-01-01'))->format('m/d/Y h:i:s');
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
            Craft::error('ImageShop API request failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }

        if ($response->getStatusCode() == 200) {
            return $response->getBody()->getContents();
        }

        return null;
    }

}
