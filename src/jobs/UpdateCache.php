<?php

namespace Imageshop\Imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use Imageshop\Imageshop\ImageShop;

/**
 * Gets all the data of documents that have chagned since the last time the scan 
 * was run, and saves them in the db in a dump.
 */
class UpdateCache extends BaseJob
{
    public function execute($queue): void
    {
        ImageShop::getInstance()->service->updateRecentlyUpdatedCache();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('imageshop-da', 'Getting recently changed Imageshop DAM documents');
    }
}
