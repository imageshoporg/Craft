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

## ImageShop DAM field

To retrieve images from the ImageShop service, you must first create an ImageShop DAM field and assign it to an entry or any another element. The field can hold multiple images and images can be reordered within the field by dragging the grab‑icon that appears in the top‑left corner of each image.

In addition to the image itself, metadata such as the image title and alt text are pulled from the service. This texts exists in several languages, and the plugin displays the appropriate version based on the site’s current language. If current site language content was not present in data pulled from service, default language content will be used. Important: Because of technical constraints, the ImageShop field must now be set to translatable.

You can override the image description and alt text by clicking the cog icon that appears in the top‑left corner when you hover over an image in the control panel. Doing so reveals the override input fields. This works for all languages, and you can also provide descriptions and alt text for languages that were not originally available in the ImageShop service.

## Templating:


### Plain and simple

Please note the `getAltText()` method which uses image alt text for the current site language. You can also use `getDescription()` method to grab image description.

Note: Just like templating an assets field, the field will always return an array.

```twig
<img src="{{ entry.imageshopField|first.url }}" alt="{{ entry.imageshopField|first.getAltText() }}">
```

### Multiple images
```twig
{% for image in entry.imageshopField %}
    <img src="{{ image.url }}" alt="{{ image.getAltText() }}">
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




### Focal Point

The ImageShop picker lets editors set a focal point on images ("Lagre fokuspunkt"). The plugin exposes this as CSS-ready percentage values so that `object-fit: cover` crops around the subject instead of dead-center.

`image.focalPoint` returns an object with `x` and `y` values (0–100, matching CSS `object-position` percentages), or `null` if no focal point has been set.

#### Setting a focal point

1. Open the ImageShop picker for an image in the control panel.
2. Click the focal point button ("Lagre fokuspunkt") and click on the desired focus area.
3. Save the entry — the focal point coordinates are stored alongside the image data.

#### Basic usage

```twig
{% set image = entry.imageshopField|first %}
{% set fp = image.focalPoint %}
<img
    src="{{ image.url }}"
    alt="{{ image.getAltText() }}"
    style="object-fit: cover;{% if fp %} object-position: {{ fp.x }}% {{ fp.y }}%{% endif %}"
>
```

#### Multiple images

```twig
{% for image in entry.imageshopField %}
    {% set fp = image.focalPoint %}
    <img
        src="{{ image.url }}"
        alt="{{ image.getAltText() }}"
        style="object-fit: cover;{% if fp %} object-position: {{ fp.x }}% {{ fp.y }}%{% endif %}"
    >
{% endfor %}
```

#### As a CSS class helper

If you prefer keeping styles out of your markup, you can output a `<style>` block or use a CSS variable:

```twig
{% set image = entry.imageshopField|first %}
{% set fp = image.focalPoint %}
<img
    class="hero-image"
    src="{{ image.url }}"
    alt="{{ image.getAltText() }}"
    {% if fp %}style="--focal-x: {{ fp.x }}%; --focal-y: {{ fp.y }}%"{% endif %}
>
```

```css
.hero-image {
    object-fit: cover;
    object-position: var(--focal-x, 50%) var(--focal-y, 50%);
}
```

#### GraphQL

The `focalPoint` field is available in GraphQL queries and returns a JSON string with `x` and `y` percentages, or `null`:

```graphql
{
    entries {
        ... on blog_blog_Entry {
            heroImage {
                url
                focalPoint
            }
        }
    }
}
```

Response:

```json
{
    "url": "https://...",
    "focalPoint": "{\"x\":35.8,\"y\":18.85}"
}
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
Focal Point:    {{ entry.imageshopField.focalPoint }}
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

You can also define a default OpenGraph image. This image will be used when the ImageShop field assigned to the current element is empty, when no ImageShop field is assigned at all, or when no element s associated with specific page.

To enable this behavior, select Global in the “Global set which will be used as source for the default opengraph image.” setting and ensure that a field that is specified in “ImageShop field used to generate OpenGraph image.” setting is assigned to the global set.
