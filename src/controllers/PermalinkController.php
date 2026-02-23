<?php

namespace webdna\imageshop\controllers;

use Craft;
use craft\web\Controller;
use webdna\imageshop\ImageShop;

class PermalinkController extends Controller
{
    protected array|int|bool $allowAnonymous = ['get-hq-url'];

    public function actionGetHqUrl(): \yii\web\Response
    {
        $request = Craft::$app->getRequest();
        $documentId = (int) $request->getRequiredParam('documentId');
        $width = (int) ($request->getParam('width') ?: 1920);
        $height = (int) ($request->getParam('height') ?: 0);

        if ($documentId <= 0) {
            return $this->asJson(['error' => 'Invalid documentId']);
        }

        $url = ImageShop::getInstance()->service->getCachedPermalink($documentId, $width, $height);

        if (!$url) {
            return $this->asJson(['error' => 'Could not generate permalink']);
        }

        return $this->asJson(['url' => $url]);
    }
}
