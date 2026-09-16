<?php

namespace Imageshop\Imageshop\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use Imageshop\Imageshop\ImageShop;
use yii\console\ExitCode;

/**
 * Syncs image metadata from Imageshop into Craft.
 *
 * This is the scheduling entry point: run it from cron to keep alt text,
 * descriptions, credits, rights, tags and titles in step with Imageshop
 * without anyone pressing the button under Utilities → Imageshop.
 *
 *     # Every 15 minutes, processing elements immediately
 *     0,15,30,45 * * * * php craft imageshop-dam/sync/run --inline
 *
 * Alt text and descriptions entered in Craft are stored as local overrides
 * and are never overwritten by the sync.
 */
class SyncController extends Controller
{
    /**
     * @inheritdoc
     */
    public $defaultAction = 'run';

    /**
     * @var bool Process affected elements now instead of queueing one job per
     * element. Use this from cron when no queue runner is active.
     */
    public bool $inline = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'run') {
            $options[] = 'inline';
        }

        return $options;
    }

    /**
     * Fetches documents changed in Imageshop since the last run and updates
     * every element that uses them.
     */
    public function actionRun(): int
    {
        $this->stdout("Checking Imageshop for changed documents...\n");

        $result = ImageShop::getInstance()->sync->run($this->inline);

        if ($result['status'] === 'failed') {
            $this->stderr("Could not reach the Imageshop API. Nothing was changed and the sync window was not advanced.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }

        if ($result['status'] === 'partial') {
            $this->stdout("Some document requests failed. The fetched documents were applied; the sync window was not advanced, so the rest are retried next run.\n", Console::FG_YELLOW);
        }

        $this->stdout("Documents changed: ", Console::FG_YELLOW);
        $this->stdout("{$result['documentsChanged']}\n");

        foreach ($result['details'] as $doc) {
            $this->stdout("  - {$doc['documentId']} {$doc['name']}\n", Console::FG_GREY);
        }

        $this->stdout($result['inline'] ? 'Elements synced: ' : 'Sync jobs queued: ', Console::FG_YELLOW);
        $this->stdout("{$result['elements']}\n");

        if (!$result['inline'] && $result['elements'] > 0) {
            $this->stdout("Run `php craft queue/run` if no queue runner is active.\n", Console::FG_GREY);
        }

        if ($result['elements'] === 0) {
            $this->stdout("No changes to apply.\n", Console::FG_GREEN);
        }

        return ExitCode::OK;
    }

    /**
     * Shows the most recent sync runs.
     */
    public function actionStatus(): int
    {
        $log = ImageShop::getInstance()->service->getSyncLog(10);

        if (empty($log)) {
            $this->stdout("No syncs have been run yet.\n");
            return ExitCode::OK;
        }

        $labels = [
            'success' => 'success',
            'no_changes' => 'no changes',
            'partial' => 'partial (API errors, will retry)',
            'failed' => 'failed (API unreachable)',
        ];

        foreach ($log as $row) {
            $status = $labels[$row['status']] ?? $row['status'];
            $this->stdout("{$row['dateCreated']}  ", Console::FG_YELLOW);
            $this->stdout("documents: {$row['documentsChanged']}  elements: {$row['jobsQueued']}  {$status}\n");
        }

        return ExitCode::OK;
    }
}
