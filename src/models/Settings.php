<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

namespace webdna\imageshop\models;

use webdna\imageshop\ImageShop;

use Craft;
use craft\base\Model;

/**
 * @author    WebDNA
 * @package   Imageshop
 * @since     2.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================

    public string $token = '';
    
    public string $key = '';
    
    public string $language = 'no';

    public ?int $openGraphFieldId = null;
    public ?int $openGraphGlobalId = null;
    

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['token', 'key', 'language'], 'string'],
            [['token', 'key', 'language'], 'required'],
        ];
    }

    public function getOpengraphFieldOptions()
    {
        $fields = \Craft::$app->fields->getAllFields();
        $fields = array_filter($fields, function($field){
            return get_class($field) == \webdna\imageshop\fields\ImageShopField::class;
        });

        $options = [
            [
                'value' => null,
                'label' => 'Select',
            ]
        ];

        $optionsFields = array_map(function($field){
            return [
                'value' => $field->id,
                'label' => $field->name,
            ];
        }, $fields);
        $options = array_merge($options, $optionsFields);
        return $options;
    }

    public function getOpengraphGlobalOptions()
    {
        $allSets = Craft::$app->globals->getAllSets();
        $options = [
            [
                'value' => null,
                'label' => 'Select',
            ]
        ];

        $optionsFields = array_map(function($field){
            return [
                'value' => $field->id,
                'label' => $field->name,
            ];
        }, $allSets);
        $options = array_merge($options, $optionsFields);
        return $options;
    }
}
