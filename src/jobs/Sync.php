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
 * Every outcome is reported back to the run: the run's watermark only
 * advances once all of its jobs have completed successfully. A save that
 * fails, or an API fetch that fails, is reported as a failure and then
 * rethrown so the job shows up as failed in the queue with the reason and
 * can be retried from there. Until it succeeds, the run never completes, so
 * the next sync run picks the element up again.
 *
 * Jobs queued by versions before 3.3.0 carried `rowId`, `rowUid`, a string
 * `documentIds` and a string `fields`. Those payloads must still unserialize
 * (hence the attribute, and no typed property reusing those names) and then
 * hit the `elementType` guard and finish as a no-op.
 */
#[\AllowDynamicProperties]
class Sync extends BaseJob
{
    public int $runId = 0;
    public string $elementType = '';
    public int $elementId = 0;
    public int $siteId = 0;
    /** @var string[] */
    public array $fieldHandles = [];
    /** @var int[] The documents this element references that the run fetched */
    public array $documents = [];
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

        try {
            $this->apply();
        } catch (\Throwable $e) {
            if ($this->runId) {
                $plugin->service->completeSyncJob($this->runId, false);
            }
            throw $e;
        }

        if ($this->runId) {
            $plugin->service->completeSyncJob($this->runId, true);
        }
    }

    /**
     * Loads the run's snapshot (or refetches the documents) and applies it to
     * the element. Throws when the element could not be updated.
     */
    protected function apply(): void
    {
        $plugin = ImageShop::getInstance();
        $documentCache = $this->runId ? $plugin->service->getDocumentCache($this->runId) : [];

        if (empty($documentCache)) {
            if (empty($this->documents)) {
                return;
            }

            // Snapshot gone: fetch what this element needs directly, in the
            // languages it already has plus every site's language, which is
            // the same set the run itself would have used.
            $languages = array_values(array_unique(array_merge($this->languages, $plugin->sync->getSiteLanguages())));
            $fetched = $plugin->sync->fetchDocuments($this->documents, $languages);
            if ($fetched['failures'] > 0) {
                throw new \RuntimeException("Sync snapshot for run {$this->runId} is gone and the Imageshop API request to refetch documents " . implode(', ', $this->documents) . ' failed.');
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
