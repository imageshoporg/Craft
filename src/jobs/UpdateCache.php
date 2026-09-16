<?php

namespace Imageshop\Imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use Imageshop\Imageshop\ImageShop;

/**
 * Fetches the metadata of every in-use document that changed in Imageshop
 * since the last sync, and stores it in the document cache.
 */
class UpdateCache extends BaseJob
{
    public function execute($queue): void
    {
        ImageShop::getInstance()->sync->updateRecentlyUpdatedCache();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('imageshop-dam', 'Getting recently changed Imageshop DAM documents');
    }
}
