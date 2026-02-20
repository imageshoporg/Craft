<?php
/**
 * ImageShop plugin for Craft CMS 3.x
 *
 * ImageShop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

namespace webdna\imageshop\models;

use webdna\imageshop\ImageShop as Plugin;

use Craft;
use craft\base\Model;
use craft\base\Serializable;
use craft\helpers\Json;

/**
 * @author    WebDNA
 * @package   ImageShop
 * @since     2.0.0
 */
class ImageShop extends Model implements Serializable
{
    // Public Properties
    // =========================================================================

    protected mixed $_json = null;

    protected ?string $_siteLanguage = null;

    // Public Methods
    // =========================================================================

    public function __construct($json, $config = [])
    {
        $this->_json = Json::decodeIfJson($json, true);
        parent::__construct($config);
    }

    public function getWidth(): ?string
    {
        if (isset($this->_json["image"]["width"])) {
            return $this->_json["image"]["width"];
        }

        return null;
    }

    public function getHeight(): ?string
    {
        if (isset($this->_json["image"]["height"])) {
            return $this->_json["image"]["height"];
        }

        return null;
    }

    public function getUrl(): ?string
    {
        return $this->getImage();
    }

    public function getBaseUrl(): ?string
    {
        if (isset($this->_json["image"]["file"])) {
            return $this->_json["image"]["file"];
        }

        return null;
    }

    public function getImage(): ?string
    {
        if (isset($this->_json["image"]["file"])) {
            $base = $this->_json["image"]["file"];
            $filename = $this->getFilename();

            return $base . "/" . $filename;
        }

        return null;
    }

    public function getFilename(): ?string
    {
        if (isset($this->_json["image"]["file"])) {
            $url = $this->_json["image"]["file"];
            $path = parse_url($url, PHP_URL_PATH);
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            return trim($path, "/") . ($ext ? '' : '.jpg');
        }

        return null;
    }

    public function getCode(): ?string
    {
        if (isset($this->_json["code"])) {
            return $this->_json["code"];
        }

        return null;
    }

    public function getRaw(): ?string
    {
        return Json::encode($this->_json);
    }

    public function getJson(): mixed
    {
        return $this->_json;
    }

    public function getDocumentId(): ?string
    {
        if (isset($this->_json["documentId"])) {
            return $this->_json["documentId"];
        }

        return null;
    }

    public function setSiteLanguage(string $lang): void
    {
        $this->_siteLanguage = $lang;
    }

    protected function getLang($lang = null): ?string
    {
        if(is_null($lang)){
            $lang = $this->_siteLanguage ?? Craft::$app->getSites()->getCurrentSite()->language;
        }
        return Plugin::getInstance()->service->sanitizeLanguage($lang);
    }

    public function getTags($lang = null): array
    {
        $tags = $this->getTextInfo("tags", $lang);

        if (is_string($tags)) {
            return explode(" ", $tags);
        }

        // No tags
        return [];
    }

    public function getTitle($lang = null): ?string
    {
        return $this->getTextInfo("title", $lang);
    }

    public function getDescription($lang = null): ?string
    {
        return $this->getTextInfo("description", $lang);
    }

    public function getRights($lang = null): ?string
    {
        return $this->getTextInfo("rights", $lang);
    }

    public function getCredits($lang = null): ?string
    {
        return $this->getTextInfo("credits", $lang);
    }

    public function getAltText($lang = null): ?string
    {
        return $this->getTextInfo("altText", $lang);
    }

    protected function getTextInfo($key, $lang = null): ?string
    {
        $lang = $this->getLang($lang);

        if (!isset ($this->_json["text"][$lang])) {
            return null;
        }

        if (!isset ($this->_json["text"][$lang][$key])) {
            return null;
        }

        return $this->_json["text"][$lang][$key];
    }

    public function getAdminLabel()
    {
        $description = $this->getDescription();
        if($description){
            return $description;
        }
        $title = $this->getTitle();
        if(!empty($title)){
            return $title;
        }
        return $this->getCode();
    }

    public function getData(): ?string
    {
        return Json::encode($this->_json);
    }

    /**
     * Returns the object's serialized value.
     *
     * @return mixed The serialized value
     */
    public function serialize(): ?string
    {
        return Json::encode($this->_json);
    }

    public function __toString(): string
    {
        return $this->getUrl() ?? "";
    }
}
