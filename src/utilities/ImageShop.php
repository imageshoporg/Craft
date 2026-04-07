<?php

namespace webdna\imageshop\utilities;

use Craft;
use craft\base\Utility;
use webdna\imageshop\ImageShop as Plugin;

/**
 * Sync utility
 */
class ImageShop extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('imageshop-dam', 'Imageshop');
    }

    public static function id(): string
    {
        return 'imageshop-dam';
    }

    public static function iconPath(): ?string
    {
        return null;
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate(
            'imageshop-dam/_components/utilities/index.twig',
            [
                'syncLog' => Plugin::getInstance()->service->getSyncLog(),
            ]
        );
    }
}
