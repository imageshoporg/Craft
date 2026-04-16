<?php

namespace webdna\imageshop\controllers;

use Craft;
use craft\web\Controller;
use webdna\imageshop\ImageShop;

class PickerController extends Controller
{
    protected array|int|bool $allowAnonymous = [];

    public function actionGetUrl(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $request = Craft::$app->getRequest();

        $options = [
            'showSizeDialogue' => (bool) $request->getBodyParam('showSizeDialogue', false),
            'showCropDialogue' => (bool) $request->getBodyParam('showCropDialogue', false),
            'showDescription'  => (bool) $request->getBodyParam('showDescription', false),
            'allowMultiple'    => (bool) $request->getBodyParam('allowMultiple', false),
            'sizes'            => (string) $request->getBodyParam('sizes', ''),
            'culture'          => (string) $request->getBodyParam('culture', ''),
        ];

        $url = ImageShop::getInstance()->service->getPickerUrl($options);

        if (!$url) {
            return $this->asJson([
                'error' => Craft::t('imageshop-dam', 'Could not obtain ImageShop access token. Check plugin settings and try again.'),
            ])->setStatusCode(400);
        }

        return $this->asJson(['url' => $url]);
    }
}
