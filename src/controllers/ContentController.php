<?php

namespace webdna\imageshop\controllers;
use craft\web\Controller;
use Craft;
use webdna\imageshop\models\ImageShop as ImageShopModel;


class ContentController extends Controller
{

    public $enableCsrfValidation = false;

    public function actionGetImageList()
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('accessCp')) {
            return $this->asJson([
                'success' => false,
                'error' => 'User does not have control panel access.',
            ]);
        }

        // get data
        $request = Craft::$app->getRequest();
        $json = $request->getBodyParam('jsonData');
        $language = $request->getBodyParam('language');

        $images = array_map(fn($image) => new ImageShopModel($image), array_filter($json, fn($image) => !empty($image)));

        $view = Craft::$app->getView();
        $html = $view->renderTemplate('imageshop-dam/_components/fields/input-list.twig', [
            'images' => $images,
            'language' => $language,
        ], \craft\web\View::TEMPLATE_MODE_CP);

        return $this->asJson([
            'result' => $html,
        ]);
    }
}