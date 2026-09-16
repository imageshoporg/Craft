<?php

namespace Imageshop\Imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use Imageshop\Imageshop\ImageShop;

/**
 * Applies one sync run's document cache to one element in one site, saving
 * it through the element lifecycle so caches and the search index are
 * refreshed.
 *
 * The job carries the id of the run that queued it and reads that run's
 * cache, so a later run (even a no-change one) cannot pull the data out from
 * under jobs that are still waiting in the queue.
 */
class Sync extends BaseJob
{
    public int $runId = 0;
    public string $elementType = '';
    public int $elementId = 0;
    public int $siteId = 0;
    /** @var string[] */
    public array $fieldHandles = [];
    public int $index = 0;
    public int $count = 0;

    public function execute($queue): void
    {
        $this->setProgress($queue, $this->index / max($this->count, 1));

        if ($this->elementType === '' || !$this->elementId || !$this->siteId || !$this->runId) {
            return;
        }

        $plugin = ImageShop::getInstance();
        $documentCache = $plugin->service->getDocumentCache($this->runId);
        if (empty($documentCache)) {
            return;
        }

        $plugin->sync->syncElement(
            $this->elementType,
            $this->elementId,
            $this->siteId,
            $this->fieldHandles,
            $documentCache
        );
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('imageshop-dam', 'Re-syncing imageshop data {index} of {count}', [
            'index' => $this->index,
            'count' => $this->count,
        ]);
    }
}
