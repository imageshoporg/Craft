<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://www.imageshop.org
 * @copyright Copyright (c) 2022 Imageshop
 */

namespace Imageshop\Imageshop\models;

use Imageshop\Imageshop\ImageShop as Plugin;

use Craft;
use craft\base\Model;
use craft\base\Serializable;
use craft\helpers\Json;

/**
 * @author    Imageshop
 * @package   Imageshop
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

    /**
     * Sets the language used by the getters when no explicit language is
     * passed. The code is canonicalized the same way an explicit argument is,
     * so `text` and `overrides` keys are always looked up under one form
     * (`nb-NO` and `no` both resolve to `no`).
     */
    public function setSiteLanguage(string $lang): void
    {
        $this->_siteLanguage = Plugin::getInstance()->service->sanitizeLanguage($lang) ?: $lang;
    }

    protected function getLang($lang = null): ?string
    {
        if (!is_null($lang)) {
            return Plugin::getInstance()->service->sanitizeLanguage($lang);
        }
        if ($this->_siteLanguage) {
            return $this->_siteLanguage;
        }
        $site = Craft::$app->getSites()->getCurrentSite();
        return Plugin::getInstance()->service->getImageshopLanguageForSite($site);
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

    /**
     * The description for the given language: the local override if an editor
     * entered one in Craft, otherwise the text synced from Imageshop.
     */
    public function getDescription($lang = null): ?string
    {
        return $this->getOverride('description', $lang) ?? $this->getTextInfo("description", $lang);
    }

    /**
     * The description as synced from Imageshop, ignoring any local override.
     */
    public function getSyncedDescription($lang = null): ?string
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

    /**
     * The alt text for the given language: the local override if an editor
     * entered one in Craft, otherwise the text synced from Imageshop.
     */
    public function getAltText($lang = null): ?string
    {
        return $this->getOverride('altText', $lang) ?? $this->getTextInfo("altText", $lang);
    }

    /**
     * The alt text as synced from Imageshop, ignoring any local override.
     */
    public function getSyncedAltText($lang = null): ?string
    {
        return $this->getTextInfo("altText", $lang);
    }

    /**
     * Returns a local override entered in Craft, or null when there is none.
     *
     * Overrides live in the image JSON's `overrides` block, separate from the
     * `text` block the sync writes, so a sync can never destroy them. An
     * empty string counts as "no override": clearing the field in the CP
     * falls back to the synced text.
     *
     * @param string $key `altText` or `description`
     * @param string|null $lang Language code, or null for the site language
     */
    public function getOverride(string $key, $lang = null): ?string
    {
        $lang = $this->getLang($lang);
        $value = $this->_json['overrides'][$lang][$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }

    /**
     * Whether an editor has entered a local override for the given key and language.
     */
    public function hasOverride(string $key, $lang = null): bool
    {
        return $this->getOverride($key, $lang) !== null;
    }

    /**
     * All local overrides, keyed by language: [lang => [key => value]].
     */
    public function getOverrides(): array
    {
        $overrides = $this->_json['overrides'] ?? [];

        return is_array($overrides) ? $overrides : [];
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

    public function getAdminLabel($lang = null)
    {
        $title = $this->getTitle($lang);
        if (!empty($title)) {
            return $title;
        }
        $description = $this->getDescription($lang);
        if ($description) {
            return $description;
        }
        return $this->getCode();
    }

    public function getFocalPoint(): ?array
    {
        if (!isset($this->_json['focalPoint']['x'], $this->_json['focalPoint']['y'])) {
            return null;
        }

        return [
            'x' => ($this->_json['focalPoint']['x'] + 1) * 50,
            'y' => (1 - $this->_json['focalPoint']['y']) * 50,
        ];
    }

    public function getResizedUrl(int $width, int $height = 0): ?string
    {
        $documentId = $this->getDocumentId();
        if (!$documentId) {
            return $this->getUrl();
        }

        $url = Plugin::getInstance()->service->getCachedPermalink((int)$documentId, $width, $height);
        return $url ?? $this->getUrl();
    }

    public function getSrcset(array $widths = [480, 960, 1920]): ?string
    {
        $documentId = $this->getDocumentId();
        if (!$documentId) {
            return null;
        }

        $parts = [];
        foreach ($widths as $w) {
            $url = Plugin::getInstance()->service->getCachedPermalink((int)$documentId, (int)$w);
            if ($url) {
                $parts[] = $url . ' ' . $w . 'w';
            }
        }

        return !empty($parts) ? implode(', ', $parts) : null;
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
