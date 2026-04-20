<?php

namespace webdna\imageshop\controllers;
use craft\web\Controller;
use Craft;
use webdna\imageshop\ImageShop;
use webdna\imageshop\models\ImageShop as ImageShopModel;


class ContentController extends Controller
{

    /**
     * Re-fetches document metadata from the ImageShop API for given document IDs.
     * Called after the popup closes to get the latest text (description, alt text, etc.)
     * that the user may have edited in the popup.
     */
    public function actionRefreshMetadata()
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $request = Craft::$app->getRequest();
        $documentIds = $request->getBodyParam('documentIds', []);
        $languages = $request->getBodyParam('languages', []);

        if (empty($documentIds) || empty($languages)) {
            return $this->asJson(['result' => []]);
        }

        $service = ImageShop::getInstance()->service;
        $result = [];

        foreach ($documentIds as $docId) {
            $docId = (int)$docId;
            if (!$docId) continue;

            $perLang = [];
            foreach ($languages as $lang) {
                $apiData = $service->getDocumentById($docId, $lang);
                if ($apiData) {
                    $perLang[$service->sanitizeLanguage($lang)] = $apiData;
                }
            }
            $result[$docId] = $perLang;
        }

        return $this->asJson(['result' => $result]);
    }

    public function actionGetImageList()
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        // get data
        $request = Craft::$app->getRequest();
        $json = $request->getBodyParam('jsonData');
        $language = $request->getBodyParam('language');

        $images = array_map(function ($image) use ($language) {
            $model = new ImageShopModel($image);
            if (is_string($language) && $language !== '') {
                $model->setSiteLanguage($language);
            }
            return $model;
        }, array_filter($json, fn($image) => !empty($image)));

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
