<?php

namespace Imageshop\Imageshop\utilities;

use Craft;
use craft\base\Utility;
use Imageshop\Imageshop\ImageShop as Plugin;

/**
 * Sync utility
 */
class ImageShop extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('imageshop-da', 'Imageshop');
    }

    public static function id(): string
    {
        return 'imageshop-da';
    }

    public static function iconPath(): ?string
    {
        return null;
    }

    public static function contentHtml(): string
    {
        return Craft::$app->getView()->renderTemplate(
            'imageshop-da/_components/utilities/index.twig',
            [
                'syncLog' => Plugin::getInstance()->service->getSyncLog(),
            ]
        );
    }
}
