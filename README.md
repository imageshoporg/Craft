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

## Imageshop DAM field

To retrieve images from the Imageshop service, you must first create an Imageshop DAM field and assign it to an entry or any another element. The field can hold multiple images and images can be reordered within the field by dragging the grab‑icon that appears in the top‑left corner of each image.

In addition to the image itself, metadata such as the image title and alt text are pulled from the service. This texts exists in several languages, and the plugin displays the appropriate version based on the site’s current language. If current site language content was not present in data pulled from service, default language content will be used. Important: Because of technical constraints, the Imageshop field must now be set to translatable.

You can override the image description and alt text by clicking the cog icon that appears in the top‑left corner when you hover over an image in the control panel. Doing so reveals the override input fields. This works for all languages, and you can also provide descriptions and alt text for languages that were not originally available in the Imageshop service.

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

The Imageshop picker lets editors set a focal point on images ("Lagre fokuspunkt"). The plugin exposes this as CSS-ready percentage values so that `object-fit: cover` crops around the subject instead of dead-center.

`image.focalPoint` returns an object with `x` and `y` values (0–100, matching CSS `object-position` percentages), or `null` if no focal point has been set.

#### Setting a focal point

1. Open the Imageshop picker for an image in the control panel.
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

## Using Imageshop Field as an OpenGraph Image Source (SEOmatic)

An Imageshop field can be used as the source for OpenGraph and Twitter Card images via the [SEOmatic](https://plugins.craftcms.com/seomatic) plugin. When configured, the plugin automatically sets `og:image`, `twitter:image`, and `seo:image` meta tags, including image dimensions and alt text.

### Requirements

- SEOmatic plugin must be installed and enabled.

### Setup

1. Go to **Settings -> Plugins -> Imageshop DAM** in the control panel.
2. Under **”Imageshop field used to generate OpenGraph image”**, select the Imageshop field that should provide the image (e.g. your “Hero Image” field).
3. The selected field must be assigned to the field layout of the element types (Entries, Categories, etc.) where you want the OpenGraph image to appear. The first image from the field will be used.

### Default / fallback image

You can configure a fallback image that is used when:
- The Imageshop field on the current element is empty
- The element does not have the configured Imageshop field in its field layout
- No element is associated with the current page

To set a fallback:
1. In the Imageshop plugin settings, under **”Global set which will be used as source for the default OpenGraph image”**, select a Global Set.
2. Make sure the same Imageshop field (from step 2 above) is assigned to that Global Set's field layout and has an image selected.

### What gets set

When an Imageshop image is found, the following SEOmatic meta tags are set automatically:

| Meta tag | Value |
|----------|-------|
| `og:image` / `twitter:image` | Image URL |
| `og:image:width` / `twitter:image:width` | Image width (if available) |
| `og:image:height` / `twitter:image:height` | Image height (if available) |
| `og:image:alt` / `twitter:image:alt` | Alt text for the current site language |

### CP preview

The **SEO Preview** sidebar in the entry editor will show the Imageshop image in the Twitter and Facebook card previews, so editors can verify the social sharing appearance before publishing.

## High-Quality Image Permalinks

The plugin provides an API endpoint for generating high-quality image URLs on-demand via the Imageshop Permalink API. This is useful for lightbox/modal popups where you want to show a higher resolution version than the default field image.

### How it works

Imageshop fields store images at the resolution configured in the field settings (e.g. 1920px wide). The permalink endpoint lets you request a larger version (default 3840px) from the Imageshop CDN, returning a permanent URL.

### Endpoint

```
GET /actions/imageshop-dam/permalink/get-hq-url
```

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `documentId` | integer | Yes | — | The Imageshop document ID (`image.documentId`) |
| `width` | integer | No | 3840 | Desired image width in pixels |
| `height` | integer | No | 0 | Desired image height (0 = auto/proportional) |

Returns JSON:
```json
{"url": "https://v.imgi.no/..."}
```

This endpoint is publicly accessible (no authentication required) and is designed to be called from frontend JavaScript.

### Example: Gallery with lightbox

This example shows a gallery grid with thumbnail-quality images. When an image is clicked, the stored image is shown immediately in a modal, and a high-quality version is fetched in the background and swapped in once loaded.

```twig
{# Render the gallery grid #}
<div class="gallery">
    {% for img in entry.galleryField %}
        <figure data-index="{{ loop.index0 }}">
            <img src="{{ img.url }}" alt="{{ img.getAltText() }}">
        </figure>
    {% endfor %}
</div>

{# Modal markup #}
<div class="lightbox" id="lightbox" style="display:none">
    <img id="lightbox-img" src="" alt="">
</div>

{# Pass image data to JS including documentId for HQ fetching #}
{% js %}
var images = {{ entry.galleryField|map(img => {
    url: img.url,
    documentId: img.documentId,
    alt: img.getAltText() ?? ''
})|json_encode|raw }};

var hqCache = {};

document.querySelectorAll('.gallery figure').forEach(function(fig) {
    fig.addEventListener('click', function() {
        var i = parseInt(this.dataset.index);
        var img = images[i];
        var lbImg = document.getElementById('lightbox-img');

        // Show stored image immediately
        lbImg.src = img.url;
        document.getElementById('lightbox').style.display = 'flex';

        // Fetch and swap in HQ version
        if (img.documentId && !hqCache[img.documentId]) {
            fetch('/actions/imageshop-dam/permalink/get-hq-url?documentId=' + img.documentId)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.url) {
                        hqCache[img.documentId] = data.url;
                        var preload = new Image();
                        preload.onload = function() { lbImg.src = data.url; };
                        preload.src = data.url;
                    }
                });
        } else if (hqCache[img.documentId]) {
            lbImg.src = hqCache[img.documentId];
        }
    });
});
{% endjs %}
```

Key points:
- The `documentId` attribute on each image model provides the ID needed for the API call.
- HQ URLs are cached client-side so repeat views don't re-fetch.
- The stored image is shown instantly while the HQ version loads in the background — users see no delay.
- The default 3840px width produces images roughly 3x larger in file size than the typical 1920px field image, providing noticeably sharper detail in fullscreen/modal views.
