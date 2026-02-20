<?php

namespace webdna\imageshop\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

class ImageShopType
{
    static public function getName(): string
    {
        return 'ImageShop_Image';
    }

    static public function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::class)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity(self::class, new ObjectType([
            'name'   => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'The interface implemented by all ImageShop types.',
        ]));
    }

    public static function getFieldDefinitions(): array
    {
        return [
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The image URL.',
            ],
            'credits' => [
                'name' => 'credits',
                'type' => Type::string(),
                'description' => 'The credits for the image.',
            ],
            'description' => [
                'name' => 'description',
                'type' => Type::string(),
                'description' => 'The description of the image.',
            ],
            'data' => [
                'name' => 'data',
                'type' => Type::string(),
                'description' => 'The raw json.',
            ],
            'title' => [
                'name' => 'title',
                'type' => Type::string(),
                'description' => 'The title of the image.',
            ],
            'altText' => [
                'name' => 'altText',
                'type' => Type::string(),
                'description' => 'The alt text for the image.',
            ],
            'width' => [
                'name' => 'width',
                'type' => Type::string(),
                'description' => 'The image width.',
            ],
            'height' => [
                'name' => 'height',
                'type' => Type::string(),
                'description' => 'The image height.',
            ],
            'code' => [
                'name' => 'code',
                'type' => Type::string(),
                'description' => 'The image code.',
            ],
            'documentId' => [
                'name' => 'documentId',
                'type' => Type::string(),
                'description' => 'The document ID.',
            ],
            'rights' => [
                'name' => 'rights',
                'type' => Type::string(),
                'description' => 'The rights information for the image.',
            ],
            'focalPoint' => [
                'name' => 'focalPoint',
                'type' => Type::string(),
                'description' => 'The focal point as JSON with x/y percentages (0-100) for CSS object-position.',
                'resolve' => function ($source) {
                    $fp = $source->getFocalPoint();
                    return $fp !== null ? json_encode($fp) : null;
                },
            ],
        ];
    }
}
