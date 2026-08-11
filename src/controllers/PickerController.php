<?php

namespace Imageshop\Imageshop\controllers;

use Craft;
use craft\web\Controller;
use Imageshop\Imageshop\ImageShop;

class PickerController extends Controller
{
    protected array|int|bool $allowAnonymous = [];

    public function actionGetUrl(): \yii\web\Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $request = Craft::$app->getRequest();

        // These arrive form-encoded from jQuery, so a JS boolean false becomes
        // the string "false" — which (bool) casts to true, forcing every picker
        // option on regardless of the field settings. filter_var reads the
        // string as intended. See imageshoporg/Craft#10.
        $options = [
            'showSizeDialogue' => filter_var($request->getBodyParam('showSizeDialogue', false), FILTER_VALIDATE_BOOLEAN),
            'showCropDialogue' => filter_var($request->getBodyParam('showCropDialogue', false), FILTER_VALIDATE_BOOLEAN),
            'showDescription'  => filter_var($request->getBodyParam('showDescription', false), FILTER_VALIDATE_BOOLEAN),
            'allowMultiple'    => filter_var($request->getBodyParam('allowMultiple', false), FILTER_VALIDATE_BOOLEAN),
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
