<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

namespace webdna\imageshop\assetbundles\imageshop;

use Craft;
use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * @author    WebDNA
 * @package   Imageshop
 * @since     2.0.0
 */
class ImageShopAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = "@webdna/imageshop/assetbundles/imageshop/dist";

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/Sortable.min.js',
            'js/ImageShop.js',
        ];

        $this->css = [
            'css/ImageShop.css',
        ];

        parent::init();
    }
}
