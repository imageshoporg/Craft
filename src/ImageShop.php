<?php
/**
 * ImageShop plugin for Craft CMS 4.x
 *
 * ImageShop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

namespace webdna\imageshop;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
use craft\services\Plugins;
use craft\services\Utilities;
use webdna\imageshop\fields\ImageShopField;
use webdna\imageshop\models\Settings;
use webdna\imageshop\services\ImageShop as Service;
use webdna\imageshop\utilities\ImageShop as UtilitiesImageShop;
use yii\base\Event;

/**
 * Class ImageShop
 *
 * @author    WebDNA
 * @package   ImageShop
 * @since     2.0.0
 *
 * @property  ImageShopServiceService $imageShopService
 */
class ImageShop extends Plugin
{
    // Static Properties
    // =========================================================================

    public static ImageShop $plugin;

    // Public Properties
    // =========================================================================

    public string $schemaVersion = '2.0.0';

    public bool $hasCpSettings = true;

    public bool $hasCpSection = false;

    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();
        self::$plugin = $this;
            
        $this->setComponents([
            'service' => Service::class,
        ]);

        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = ImageShopField::class;
            }
        );

        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                }
            }
        );

        Craft::info(
            Craft::t(
                'imageshop-dam',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITY_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = UtilitiesImageShop::class;
        });



        $this->seomaticEvents();

    }

    public function getOpenGraphPictureForElement($element)
    {
        if(!$element){
            return null;
        }

        // if field defined in plugin settings and if field exists
        if(!is_int($this->getSettings()->openGraphFieldId)){
            return null;
        }


        $field = Craft::$app->fields->getFieldById($this->getSettings()->openGraphFieldId);
        if(is_null($field)){
            return null;
        }

        // if field attached to element
        $fieldLayout = $element->getFieldLayout() ?? null;
        if(is_null($fieldLayout)){
            return null;
        }

        $found = false;
        $customFields = $fieldLayout->getCustomFields();
        foreach ($customFields as $fieldInLayout) {
            if ($field->id === $fieldInLayout->id) {
                $found = true;
                break;
            }
        }

        if($found == false){
            return null;
        }

        // if proper field
        if(get_class($field) !== \webdna\imageshop\fields\ImageShopField::class){
            return null;
        }

        // if not empty
        $value = $element->getFieldValue($field->handle);
        if(empty($value)){
            return null;
        }

        // if proper value
        $imageObj = array_values($value)[0];
        if(get_class($imageObj) !== \webdna\imageshop\models\ImageShop::class){
            return null;
        }

        // if contains image
        $imageUrl = $imageObj->getUrl();
        if(empty($imageUrl)){
            return null;
        }
        return $imageUrl;
    }

    public function seomaticEvents()
    {
        // if seomatic installed
        if(Craft::$app->plugins->isPluginInstalled('seomatic') == false || Craft::$app->plugins->isPluginEnabled('seomatic') == false) {
            return;
        }

        // if current element
        $currentElement = Craft::$app->urlManager->getMatchedElement();
        $url = $this->getOpenGraphPictureForElement($currentElement);

        if(is_null($url)){
            $url = $this->getGlobalOgUrl();
            if(is_null($url)){
                return;
            }
        }

        Event::on(
            \nystudio107\seomatic\helpers\DynamicMeta::class,
            \nystudio107\seomatic\helpers\DynamicMeta::EVENT_ADD_DYNAMIC_META,
            function(\nystudio107\seomatic\events\AddDynamicMetaEvent $event) use($url) {
                \nystudio107\seomatic\Seomatic::$seomaticVariable->meta->twitterImage = $url;
                \nystudio107\seomatic\Seomatic::$seomaticVariable->meta->ogImage = $url;
                \nystudio107\seomatic\Seomatic::$seomaticVariable->meta->seoImage = $url;
            }
        );

    }

    public function getGlobalOgUrl()
    {
        $globalId = $this->getSettings()->openGraphGlobalId;
        if(!is_int($globalId)){
            return null;
        }
        $global = Craft::$app->globals->getSetById($globalId);
        $url = $this->getOpenGraphPictureForElement($global);
        return $url;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'imageshop-dam/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }
}
