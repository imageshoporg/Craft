<?php

namespace Imageshop\Imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use Imageshop\Imageshop\ImageShop;

/**
 * Runs a full metadata sync from the queue: fetches every in-use document
 * that changed in Imageshop since the last run and queues one job per
 * affected element.
 *
 * Before 3.3.0 this job only refreshed the document cache and relied on a
 * separate call to queue the element updates. A run's watermark now only
 * advances once its element jobs complete, so a fetch on its own would be
 * repeated forever; running the whole sync is what any caller of this job
 * actually wants.
 */
class UpdateCache extends BaseJob
{
    public function execute($queue): void
    {
        ImageShop::getInstance()->sync->run(false);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('imageshop-dam', 'Getting recently changed Imageshop DAM documents');
    }
}
