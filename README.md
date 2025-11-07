# Official Imageshop plugin for Craft CMS

This official plugin integrates [Imageshop Digital Asset Management system](https://www.imageshop.org) with Craft CMS by exposing
their image selector as a popup that saves the selected image data in a field so the selection
can be used in twig templates.



 ![Screenshot](./screenshot.png)


# Installation

To install the plugin, follow these instructions.

- Open your terminal and go to your Craft project:

````
cd /path/to/project
````

- Then tell Composer to load the plugin:

```
composer require webdna/imageshop-dam
```

- In the Control Panel, go to Settings → Plugins and click the “Install” button for 'Imageshop'.

OR do it via the command line

```
php craft plugin/install imageshop-dam
```

- On the settings page, fill out the token and private key field to start using the plugin.

- You will now have access to the "Imageshop" in the Field type dropdown on the field creation page.



## Templating:


### Plain and simple

Note: Just like templating an assets field, the field will always return an array.

```twig
<img src="{{ entry.imageshopField|first.url }}" alt="{{ entry.imageshopField|first.filename }}">
```

### Multiple images
```twig
{% for image in entry.imageshopField %}
    <img src="{{ image.url }}" alt="{{ image.filename }}">
{% endfor %}
```

### Using Imager

## Single size

```twig
{% set image = craft.imager.transformImage(entry.imageshopField.url, { width: 400 }) %}
<img src="{{ image.url }}">
```


## Multiple sizes
```twig
{% set transforms = craft.imager.transformImage(
    entry.imageshopField.url,
    [
        { width: 200 },
        { width: 800 },
        { width: 1200 },
        { width: 1920 }
    ]
    ) %}

{% for image in transforms %}
    <img src="{{ image.url }}" width="{{ image.width }}" style="width: auto;margin: 20px;">
{% endfor %}
```


## Responsive images with srcset

```twig
{% set transformedImages = craft.imager.transformImage(image,[
        { width: 1920, jpegQuality: 90, webpQuality: 90 },
        { width: 1200, jpegQuality: 75, webpQuality: 75 },
        { width: 800, jpegQuality: 75, webpQuality: 75 },
        { width: 400, jpegQuality: 65, webpQuality: 65 },
    ]) %}

<img srcset="{{ craft.imager.srcset(transformedImages) }}">
```




### Available attributes

```imageshopField``` is the name of the field in these examples.

 ```twig
Code:           {{ entry.imageshopField.code }}
Image:          {{ entry.imageshopField.image }}
Tags:           {{ entry.imageshopField.tags("no") | join(", ") }}
Title:          {{ entry.imageshopField.title }}
Rights:         {{ entry.imageshopField.rights }}
Description:    {{ entry.imageshopField.description }}
Credit:         {{ entry.imageshopField.credits }}
DocumentId:     {{ entry.imageshopField.documentId }}
Raw:            {{ entry.imageshopField.json | json_encode(constant("JSON_PRETTY_PRINT")) }}
```

## Sync metadata utility

Imageshop provides a utility tool for updating image metadata.

To access it, go to Utilities → Imageshop, and click Sync Metadata.

This action will add a job to the queue that updates all elements - including Entries, Categories, Users, Global Sets, Assets, Matrix Blocks, and Commerce Products - that contain Imageshop fields. The content will be refreshed with the latest data from the API, such as image descriptions and alt text.

This is useful in two situations:

* When an image in the Imageshop service has been updated since it was last added to the Craft CMS system.

* When you want to override any manual changes made to images (for example, manually edited alt text).

## Using Imageshop Field as an OpenGraph Image Source

An Imageshop field can be used as the source for the OpenGraph image in the SEOmatic plugin.
To enable this functionality, first define which field should be used as the image source in the Imageshop plugin settings, under “Imageshop field used to generate OpenGraph image.”

This field must be assigned to the field layout of the specific element type - for example, an Entry, Category, User, Product, or any other element - for which you want to override the OpenGraph image. The first image from this field will be used.


