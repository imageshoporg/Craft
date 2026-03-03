<?php

namespace webdna\imageshop\controllers;

use craft\web\Controller;
use yii\web\Response;

use Craft;
use webdna\imageshop\ImageShop;

class DefaultController extends Controller
{
    public function actionCreateSyncJobs(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:imageshop-dam');

        $service = ImageShop::getInstance()->service;

        // Phase 1: Fetch recently changed documents from the ImageShop API and cache them
        $service->updateRecentlyUpdatedCache();

        // Count how many documents were cached and build details
        $documentCache = $service->getDocumentCache();
        $documentsChanged = count($documentCache);
        $details = $service->buildSyncDetails($documentCache);

        // Phase 2: Create queue jobs to update content rows from the cache
        $jobCount = $service->updateImages();

        // Log the sync run
        $service->logSync(
            $documentsChanged,
            $jobCount,
            $jobCount > 0 ? 'success' : 'no_changes',
            $details
        );

        if ($jobCount > 0) {
            Craft::$app->getSession()->setNotice(
                Craft::t('imageshop-dam', 'Queued {count} sync {count, plural, =1{job} other{jobs}}. Check the queue to monitor progress.', [
                    'count' => $jobCount,
                ])
            );
        } else {
            Craft::$app->getSession()->setNotice(
                Craft::t('imageshop-dam', 'No changes found. All metadata is up to date.')
            );
        }

        return $this->redirectToPostedUrl();
    }
}
