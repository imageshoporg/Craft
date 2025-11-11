<?php

namespace webdna\imageshop\jobs;

use Craft;
use craft\queue\BaseJob;
use webdna\imageshop\ImageShop;

/**
 * Update Fields queue job
 */
class UpdateFields extends BaseJob
{

    public $label;
    public $type;

    function execute($queue): void
    {
        $query = $this->type::find();
        $totalElements = $query->count();
        $currentElement = 0;

        try {
            $i = 0;
            foreach ($query->each() as $element) {
                $i ++;
                $this->setProgress($queue, $currentElement++ / $totalElements);
                try{
                    ImageShop::getInstance()->service->updateElement($element);
                } catch(\Exception $e){

                }
            }
        } catch (\Exception $e) {
            // Fail silently
        }


    }

    protected function defaultDescription(): ?string
    {
        return 'Updating imageshop fields - ' . $this->label;
    }
}
