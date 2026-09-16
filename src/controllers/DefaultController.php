<?php

namespace Imageshop\Imageshop\controllers;

use craft\web\Controller;
use yii\web\Response;

use Craft;
use Imageshop\Imageshop\ImageShop;

class DefaultController extends Controller
{
    /**
     * Fetches changed documents from Imageshop and queues one sync job per
     * affected element/site. Triggered by the Utilities → Imageshop button.
     */
    public function actionCreateSyncJobs(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('utility:imageshop-dam');

        $result = ImageShop::getInstance()->sync->run(false);

        if ($result['status'] === 'failed') {
            Craft::$app->getSession()->setError(
                Craft::t('imageshop-dam', 'Could not reach the Imageshop API. Nothing was changed; try again later.')
            );
        } elseif ($result['elements'] > 0) {
            Craft::$app->getSession()->setNotice(
                Craft::t('imageshop-dam', 'Queued {count} sync {count, plural, =1{job} other{jobs}}. Check the queue to monitor progress.', [
                    'count' => $result['elements'],
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
