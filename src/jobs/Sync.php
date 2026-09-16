<?php

namespace Imageshop\Imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use Imageshop\Imageshop\ImageShop;

/**
 * Applies the recently-updated document cache to one element in one site,
 * saving it through the element lifecycle so caches and the search index
 * are refreshed.
 */
class Sync extends BaseJob
{
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

        if ($this->elementType === '' || !$this->elementId || !$this->siteId) {
            return;
        }

        ImageShop::getInstance()->sync->syncElement(
            $this->elementType,
            $this->elementId,
            $this->siteId,
            $this->fieldHandles
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
