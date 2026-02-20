<?php
/**
 * Imageshop plugin for Craft CMS 4.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

namespace webdna\imageshop;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fields;
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
 * @package   Imageshop
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

    public function getOpenGraphImageForElement($element): ?\webdna\imageshop\models\ImageShop
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
        if(empty($imageObj->getUrl())){
            return null;
        }

        return $imageObj;
    }

    public function seomaticEvents()
    {
        // if seomatic installed
        if(Craft::$app->plugins->isPluginInstalled('seomatic') == false || Craft::$app->plugins->isPluginEnabled('seomatic') == false) {
            return;
        }

        // For frontend requests: fires during normal page rendering
        Event::on(
            \nystudio107\seomatic\helpers\DynamicMeta::class,
            \nystudio107\seomatic\helpers\DynamicMeta::EVENT_ADD_DYNAMIC_META,
            function(\nystudio107\seomatic\events\AddDynamicMetaEvent $event) {
                $this->applySeomaticImage();
            }
        );

        // For CP preview: EVENT_ADD_DYNAMIC_META doesn't fire during the CP
        // sidebar preview, so inject the image before the preview template renders
        Event::on(
            \craft\web\View::class,
            \craft\web\View::EVENT_BEFORE_RENDER_TEMPLATE,
            function(\craft\events\TemplateEvent $event) {
                if (strpos($event->template, 'seomatic/_sidebars/') === 0
                    && \nystudio107\seomatic\Seomatic::$matchedElement
                ) {
                    $this->applySeomaticImage();
                }
            }
        );
    }

    private function applySeomaticImage(): void
    {
        $currentElement = \nystudio107\seomatic\Seomatic::$matchedElement;

        $image = $this->getOpenGraphImageForElement($currentElement);

        if(is_null($image)){
            $image = $this->getGlobalOgImage();
        }

        if(is_null($image)){
            return;
        }

        $url = $image->getUrl();
        $meta = \nystudio107\seomatic\Seomatic::$seomaticVariable->meta;

        $meta->seoImage = $url;
        $meta->ogImage = $url;
        $meta->twitterImage = $url;

        $width = $image->getWidth();
        $height = $image->getHeight();
        if($width){
            $meta->ogImageWidth = $width;
            $meta->twitterImageWidth = $width;
        }
        if($height){
            $meta->ogImageHeight = $height;
            $meta->twitterImageHeight = $height;
        }

        $altText = $image->getAltText();
        if($altText){
            $meta->ogImageDescription = $altText;
            $meta->twitterImageDescription = $altText;
        }
    }

    public function getGlobalOgImage(): ?\webdna\imageshop\models\ImageShop
    {
        $globalId = $this->getSettings()->openGraphGlobalId;
        if(!is_int($globalId)){
            return null;
        }
        $global = Craft::$app->globals->getSetById($globalId);
        return $this->getOpenGraphImageForElement($global);
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
