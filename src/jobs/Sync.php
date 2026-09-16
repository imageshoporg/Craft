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
 * snapshot, so a later run (even a no-change one) cannot pull the data out
 * from under jobs that are still waiting in the queue. If the snapshot has
 * been pruned by the time the job runs (a queue backlogged across many later
 * runs), the job fetches its documents from the API itself instead of
 * silently doing nothing.
 *
 * A save that fails, or an API fetch that fails, throws so the job shows up
 * as failed in the queue with the reason and can be retried from there.
 */
class Sync extends BaseJob
{
    public int $runId = 0;
    public string $elementType = '';
    public int $elementId = 0;
    public int $siteId = 0;
    /** @var string[] */
    public array $fieldHandles = [];
    /** @var int[] The documents this element references that the run fetched */
    public array $documentIds = [];
    /** @var string[] Languages present in the element's stored values, for refetching */
    public array $languages = [];
    public int $index = 0;
    public int $count = 0;

    public function execute($queue): void
    {
        $this->setProgress($queue, $this->index / max($this->count, 1));

        if ($this->elementType === '' || !$this->elementId || !$this->siteId) {
            return;
        }

        $plugin = ImageShop::getInstance();
        $documentCache = $this->runId ? $plugin->service->getDocumentCache($this->runId) : [];

        if (empty($documentCache)) {
            if (empty($this->documentIds)) {
                return;
            }

            // Snapshot gone: fetch what this element needs directly, in the
            // languages it already has plus every site's language, which is
            // the same set the run itself would have used.
            $languages = array_values(array_unique(array_merge($this->languages, $plugin->sync->getSiteLanguages())));
            $fetched = $plugin->sync->fetchDocuments($this->documentIds, $languages);
            if ($fetched['failures'] > 0) {
                throw new \RuntimeException("Sync snapshot for run {$this->runId} is gone and the Imageshop API could not be reached to refetch documents " . implode(', ', $this->documentIds) . '.');
            }
            $documentCache = $fetched['cache'];
            if (empty($documentCache)) {
                return;
            }
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
