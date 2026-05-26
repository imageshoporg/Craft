<?php

namespace Imageshop\Imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use craft\helpers\Json;
use Imageshop\Imageshop\ImageShop;

/**
 * Syncs the details of documents from the recently updated cache to all the
 * content rows in the db that contain imageshop assets
 */
class Sync extends BaseJob
{
    public string $rowId = '';
    public string $rowUid = '';
    public string $documentIds = '';
    public string $fields = '';
    public int $index = 0;
    public int $count = 0;

    public function execute($queue): void
    {
        $this->setProgress($queue, $this->index / max($this->count, 1));

        ImageShop::getInstance()->service->updateContentRow([
            'rowId' => $this->rowId,
            'rowUid' => $this->rowUid,
            'documentIds' => Json::decode($this->documentIds),
            'fields' => Json::decode($this->fields),
        ]);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('imageshop-dam', 'Re-syncing imageshop data {index} of {count}', [
            'index' => $this->index,
            'count' => $this->count,
        ]);
    }
}
