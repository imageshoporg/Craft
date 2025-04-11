<?php
/**
 * ImageShop plugin for Craft CMS 3.x
 *
 * ImageShop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2025 WebDNA
 */

namespace webdna\imageshop\services;

use webdna\imageshop\ImageShop as Plugin;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\helpers\Json;

use verbb\supertable\fields\SuperTableField;
use verbb\supertable\SuperTable as SuperTablePlugin;

/**
 * @author    WebDNA
 * @package   ImageShop
 * @since     2.0.0
 */
class SuperTable extends Component
{
    // Public Methods
    // =========================================================================

    public function getAllImageShopContentRows(): array
    {
        // $fields = Plugin::getInstance()->service->getImageShopFields();
        // lets get all the supertable content tables
        $superTableTables = $this->_getSuperTableContentTables();
        $rows = [];

        foreach ($superTableTables as $table => $fields) {
            $rowsQuery = (new Query())
            ->select('*')
            ->from($table);

            
            foreach ($fields as $field) {
                $rowsQuery->orWhere(['not', [$field => null]]);
            }
            // would be better to do this with something like JSON_CONTAINS but 
            // can't be certain about db driver or version on system.

            foreach ($rowsQuery->all() as $value) {
                $row = [
                    'table' => $table,
                    'rowId' => $value['id'],
                    'rowUid' => $value['uid'],
                    'documentIds' => [],
                    'fields' => [],
                ];
                
                foreach ($fields as $field) {
                    if (array_key_exists($field,$value) && !empty($value[$field]) && Json::isJsonObject($value[$field])) {
                        $fieldValue = Json::decode($value[$field]);
                        if (!empty($fieldValue)) {

                            //Craft::dd($fieldValue);
                            // deal with pre-allow multiple update
                            if (array_key_exists('documentId', $fieldValue)) {
                            $fieldValue = [$fieldValue];
                            }
                            
                            //Craft::dd($fieldValue);
                            foreach ($fieldValue as $v) {
                                $imageData = is_array($v) ? $v : Json::decodeIfJson($v);
                                $row['documentIds'][] = $imageData['documentId'];
                                $row['fields'][$field][($imageData['documentId'])] = $imageData;
                            }
                            $rows[] = $row;
                        }
                    }
                }
            }

        }

        return $rows;
    }

    private function _getSuperTableContentTables(): array
    {
        $superTableBlockTypes = SuperTablePlugin::getInstance()->service->getAllBlockTypes();
        $tables = [];
        foreach ($superTableBlockTypes as $blockType) {
            $fields = Plugin::getInstance()->service->getImageShopFields($blockType->getFieldContext());
            if (!empty($fields)) {
                $tableName = $blockType->getField()->contentTable;
                if (array_key_exists($tableName, $tables)) {
                    $tables[$tableName] = array_merge($tables[$tableName], $fields);
                } else {
                    $tables[$tableName] = $fields;
                }
            }
        }
        return $tables;
    }
}