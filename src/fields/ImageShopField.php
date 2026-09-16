<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://www.imageshop.org
 * @copyright Copyright (c) 2022 Imageshop
 */

namespace Imageshop\Imageshop\fields;

use Imageshop\Imageshop\ImageShop;
use Imageshop\Imageshop\ImageShop as Plugin;
use Imageshop\Imageshop\models\ImageShop as Model;
use Imageshop\Imageshop\assetbundles\imageshop\ImageShopAsset;
use Imageshop\Imageshop\gql\types\ImageShopType;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use yii\db\Schema;
use yii\base\Arrayable;
use craft\helpers\Json;
use GraphQL\Type\Definition\Type;

/**
 * @author    Imageshop
 * @package   Imageshop
 * @since     2.0.0
 */
class ImageShopField extends Field
{
    // Public Properties
    // =========================================================================

    public bool $showSizeDialogue = false;

    public bool $showCropDialogue = false;

    public bool $allowMultiple = false;

    public bool $showDescription = false;

    public string $sizes = 'Normal;1920x0';

    private static array $_removedSettings = ['showCredits'];

    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('imageshop-dam', 'Imageshop DAM');
    }

    // Public Methods
    // =========================================================================

    public function __set($name, $value)
    {
        if (in_array($name, self::$_removedSettings, true)) {
            return;
        }
        parent::__set($name, $value);
    }

    /**
     * @inheritdoc
     */
    // Craft 4
    public function getContentColumnType(): array|string
    {
        return 'mediumtext';
    }

    // Craft 5
    public static function dbType(): array|string|null
    {
        return 'mediumtext';
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        $siteLanguage = null;
        if ($element) {
            $site = Craft::$app->getSites()->getSiteById($element->siteId);
            if ($site) {
                $siteLanguage = Plugin::getInstance()->service->getImageshopLanguageForSite($site);
            }
        }

        $models = null;

        if ($value instanceof Model) {
            $models = [$value];
        } elseif (is_array($value) && ArrayHelper::isIndexed($value, true)) {
            $models = array_filter($value, fn($image) => $image instanceof Model);
            if (empty($models)) {
                // Craft 5: array of JSON strings or decoded associative arrays
                $models = [];
                foreach (array_filter($value, fn($v) => !empty($v)) as $item) {
                    if (is_string($item)) {
                        $decoded = Json::decodeIfJson($item);
                        if (is_array($decoded)) {
                            $models[] = new Model($decoded);
                        }
                    } elseif (is_array($item)) {
                        $models[] = new Model($item);
                    }
                }
            }
        } elseif (is_string($value) && Json::isJsonObject($value)) {
            $json = Json::decode($value);
            if (ArrayHelper::isIndexed($json, true)) {
                $models = array_map(fn($image) => new Model($image), array_filter($json, fn($image) => !empty($image)));
            }
        } elseif (is_null($value)) {
            return [];
        }

        if ($models === null) {
            $models = [new Model($value)];
        }

        if ($siteLanguage) {
            foreach ($models as $model) {
                $model->setSiteLanguage($siteLanguage);
            }
        }

        if (!$this->allowMultiple && count($models) > 1) {
            $models = array_slice($models, 0, 1);
        }

        return $models;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (is_array($value)) {
            return array_map(fn($image) => $image instanceof Model ? $image->serialize() : $image, $value);
        }

        return parent::serializeValue($value, $element);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        // Render the settings template
        return Craft::$app->getView()->renderTemplate(
            'imageshop-dam/_components/fields/settings',
            [
                'field' => $this,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        $settings = ImageShop::$plugin->getSettings();
        // Resolve the language from the element being edited, not the request.
        // Craft 5 renders new Matrix blocks over AJAX without a `site` param.
        $language = $this->getCurrentAdminLanguage($element);
        $culture = $language ?: $settings->language;

        // Register our asset bundle
        Craft::$app->getView()->registerAssetBundle(ImageShopAsset::class);

        // Get our id and namespace
        $id = Craft::$app->getView()->formatInputId($this->handle);
        $namespacedId = Craft::$app->getView()->namespaceInputId($id);

        // Variables to pass down to our field JavaScript to let it namespace properly
        $jsonVars = [
            'id' => $id,
            'name' => $this->handle,
            'namespace' => $namespacedId,
            'prefix' => Craft::$app->getView()->namespaceInputId(''),
            'allowMultiple' => $this->allowMultiple,
            'pickerOptions' => [
                'showSizeDialogue' => $this->showSizeDialogue,
                'showCropDialogue' => $this->showCropDialogue,
                'showDescription'  => $this->showDescription,
                'sizes'            => $this->sizes,
                'allowMultiple'    => $this->allowMultiple,
                'culture'          => $culture,
            ],
            ];
        $jsonVars = Json::encode($jsonVars);
        Craft::$app->getView()->registerJs("new Craft.ImageShopDAMField(" . $jsonVars . ");");

        $value = array_filter($value, function($single){
            return !is_null($single->getJson());
        });

        $valueArray = array_map(fn($image) => $image->getJson(), $value);

        // Render the input template
        return Craft::$app->getView()->renderTemplate(
            'imageshop-dam/_components/fields/input',
            [
                'name' => $this->handle,
                'value' => $value,
                'valueArray' => $valueArray,
                'field' => $this,
                'id' => $id,
                'namespace' => $namespacedId,
                'language' => $language,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType(): Type|array
    {
        return Type::listOf(ImageShopType::getType());
    }

    /**
     * Returns the Imageshop language code the CP field should read from and
     * write to for the given element.
     *
     * The element's own site is authoritative. The `site` query param is only
     * present on full CP page loads; Craft 5 renders new Matrix blocks and
     * slideout editors over AJAX with just a `siteId` body param, so relying on
     * the request made a block added on a non-primary site render its alt text
     * and description editors under the primary site's language, and the
     * editor's text ended up in the wrong `text` block.
     *
     * Resolution order: element site → `site` handle param → `siteId` param →
     * primary site. Request params are only consulted on web requests.
     *
     * @param ElementInterface|null $element The element the field is rendered for
     */
    public function getCurrentAdminLanguage(?ElementInterface $element = null): ?string
    {
        $sites = Craft::$app->getSites();
        $site = null;

        if ($element && !empty($element->siteId)) {
            $site = $sites->getSiteById((int)$element->siteId);
        }

        if ($site === null) {
            $request = Craft::$app->getRequest();
            if ($request instanceof \craft\web\Request) {
                $handle = $request->getParam('site');
                if ($handle) {
                    $site = $sites->getSiteByHandle($handle);
                }
                if ($site === null) {
                    $siteId = $request->getParam('siteId');
                    if ($siteId) {
                        $site = $sites->getSiteById((int)$siteId);
                    }
                }
            }
        }

        if ($site === null) {
            $site = $sites->getPrimarySite();
        }

        return Plugin::getInstance()->service->getImageshopLanguageForSite($site);
    }

}
