<?php

namespace Imageshop\Imageshop\console\controllers;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use Imageshop\Imageshop\ImageShop;
use Imageshop\Imageshop\services\ImageShop as Service;
use yii\console\ExitCode;

/**
 * Inspects and clears stored Imageshop permalinks.
 *
 * Permalinks are stored permanently rather than cached, because
 * /Permalink/CreatePermaLinkFromDocumentId mints a new permalink on every
 * call. They are not expected to need clearing in normal operation — this is
 * the escape hatch for when one does.
 */
class PermalinksController extends Controller
{
    /**
     * @var int|null Limit the operation to a single Imageshop document id.
     */
    public ?int $documentId = null;

    /**
     * @var bool Skip the confirmation prompt.
     */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'clear') {
            $options[] = 'documentId';
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Shows how many permalinks are stored.
     */
    public function actionStats(): int
    {
        $total = (int)(new Query())->from(Service::PERMALINK_TABLE)->count();
        $documents = (int)(new Query())->from(Service::PERMALINK_TABLE)->select('documentId')->distinct()->count();

        $this->stdout("Stored permalinks: ", Console::FG_YELLOW);
        $this->stdout("{$total}\n");
        $this->stdout("Distinct documents: ", Console::FG_YELLOW);
        $this->stdout("{$documents}\n");

        if ($documents > 0) {
            $this->stdout("Average sizes per document: ", Console::FG_YELLOW);
            $this->stdout(round($total / $documents, 1) . "\n");
        }

        return ExitCode::OK;
    }

    /**
     * Deletes stored permalinks so they are recreated on the next request.
     *
     * Every deleted permalink causes a new one to be minted on the next
     * request, which the CDN has never seen — so clearing in bulk reintroduces
     * the cold-start cost this storage exists to avoid. Prefer --document-id.
     */
    public function actionClear(): int
    {
        $service = ImageShop::getInstance()->service;

        if ($this->documentId !== null) {
            $count = (int)(new Query())
                ->from(Service::PERMALINK_TABLE)
                ->where(['documentId' => $this->documentId])
                ->count();

            if ($count === 0) {
                $this->stdout("No stored permalinks for document {$this->documentId}.\n", Console::FG_YELLOW);
                return ExitCode::OK;
            }

            $deleted = $service->clearPermalinks($this->documentId);
            $this->stdout("Deleted {$deleted} permalink(s) for document {$this->documentId}.\n", Console::FG_GREEN);
            $this->stdout("They will be recreated on the next request, cold.\n");

            return ExitCode::OK;
        }

        $total = (int)(new Query())->from(Service::PERMALINK_TABLE)->count();

        if ($total === 0) {
            $this->stdout("No stored permalinks.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $this->stdout("This will delete all {$total} stored permalinks.\n", Console::FG_YELLOW);
        $this->stdout("Every image will get a brand new permalink on its next request, so the\n");
        $this->stdout("CDN will be cold for all of them. Use --document-id to limit the damage.\n\n");

        if (!$this->force) {
            // Yii's confirm() returns true when the controller is
            // non-interactive, so relying on it alone would let a deploy
            // script or cron job wipe every permalink with no prompt — which
            // is precisely the churn this storage exists to prevent. Require
            // --force to be explicit instead.
            if (!$this->interactive) {
                $this->stderr("Refusing to clear all permalinks non-interactively.\n", Console::FG_RED);
                $this->stderr("Pass --force if that is really what you want.\n");
                return ExitCode::USAGE;
            }

            if (!$this->confirm('Delete every stored permalink?')) {
                $this->stdout("Aborted.\n");
                return ExitCode::OK;
            }
        }

        $deleted = $service->clearPermalinks();
        $this->stdout("Deleted {$deleted} permalink(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
