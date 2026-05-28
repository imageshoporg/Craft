# Official Imageshop plugin for Craft CMS

**Supports Craft CMS 4 and Craft CMS 5.**

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
composer require imageshop/imageshop-dam
```

- In the Control Panel, go to Settings → Plugins and click the “Install” button for 'Imageshop'.

OR do it via the command line

```
php craft plugin/install imageshop-plugin
```

- On the settings page, fill out the token and private key field to start using the plugin.

- You will now have access to the "Imageshop" in the Field type dropdown on the field creation page.

## Upgrading to 2.6.0

No migrations are required. After updating, you'll see a new **Site language mappings** section on the plugin's settings page — all inputs start empty, so existing installs retain the previous auto-derived language behavior unchanged. See [Per-site language mapping](#per-site-language-mapping) for how to opt in.

## Upgrading from `webdna/imageshop-dam`

This repository now uses `imageshop/imageshop-dam` as the Composer package name and `Imageshop\Imageshop\...` as the PHP namespace.

In your Craft project (not in this plugin repository), run:

1. Update Composer dependencies.

```bash
composer update
```

2. Update field type references in project config (or DB equivalent):

```yaml
type: webdna\imageshop\fields\ImageShopField
```

to:

```yaml
type: Imageshop\Imageshop\fields\ImageShopField
```

3. Apply project config and pending updates.

```bash
php craft up
```

Notes:
- `handle` remains `imageshop-plugin`.
- No data migration is required for this namespace/vendor rename alone.
- No field content loss is expected from this change by itself.

## Upgrading to 2.5.0

After updating the plugin via Composer, run migrations to upgrade field content columns from `TEXT` to `MEDIUMTEXT`:

```bash
php craft migrate --plugin=imageshop-plugin
```

Or, if all pending migrations should be applied at once:

```bash
php craft migrate/all
```

### Craft 5 support

Version 2.5.0 supports both Craft CMS 4 and Craft CMS 5. No additional configuration is needed — the plugin detects the Craft version at runtime.

### Restored "Edit description before insert?"

The `showDescription` field setting has been restored. When enabled, the Imageshop popup shows a description input field that editors can fill in before inserting the image. The entered description is passed through to the Craft entry's description field.

To enable: edit the Imageshop field in **Settings → Fields**, and toggle **"Edit description before insert?"** on.

### Removed field settings

The **"Show Credits?"** (`showCredits`) field setting remains removed. If your project config YAML files still contain `showCredits` keys under an Imageshop field's settings, they will be silently ignored.

## Imageshop DAM field

To retrieve images from the Imageshop service, you must first create an Imageshop DAM field and assign it to an entry or any another element. The field can hold multiple images and images can be reordered within the field by dragging the grab‑icon that appears in the top‑left corner of each image.

In addition to the image itself, metadata such as the image title and alt text are pulled from the service. This texts exists in several languages, and the plugin displays the appropriate version based on the site’s current language. If current site language content was not present in data pulled from service, default language content will be used. Important: Because of technical constraints, the Imageshop field must now be set to translatable.

You can override the image description and alt text by clicking the cog icon that appears in the top‑left corner when you hover over an image in the control panel. Doing so reveals the override input fields. This works for all languages, and you can also provide descriptions and alt text for languages that were not originally available in the Imageshop service.

## Per-site language mapping

By default the plugin derives the Imageshop language code from each Craft site's language — e.g. a site whose Craft language is `nb-NO` reads Imageshop's `no` text block. If that automatic mapping isn't what you want, you can override it per site.

Go to **Settings → Plugins → Imageshop** and scroll to **Site language mappings**. Each Craft site is listed with a text input; the placeholder shows the auto-derived code. Enter the Imageshop language code you want that site to use (for example, `nn` for a Bokmål Craft site that should display Nynorsk metadata, or `en` for a Swedish site as a fallback when no Swedish texts exist in Imageshop). Leave an input empty to keep the auto-derived code.

The mapping applies everywhere the plugin resolves a site's language:

- Picker popup culture (`CULTURE` / `IMAGESHOPLANGUAGE` query params).
- Admin field labels and the alt-text / description inputs under the cog icon (the `data-current-language` attribute now reflects the mapped code).
- `ImageShop` model text getters on the front end: `getAltText()`, `getDescription()`, `getTitle()`, `getCredits()`, `getRights()`, `getTags()`.
- GraphQL text resolvers when querying a specific site (`altText`, `description`, `title`, `credits`, `rights`, `tags`).

Passing an explicit language to a model getter (e.g. `image.getAltText('en')`) still works and takes precedence over the per-site mapping — it's only the default, site-derived code that's overridden.

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

The `focalPoint` field is available in GraphQL queries — see the [GraphQL](#graphql) section for the full field reference.

## GraphQL

The Imageshop field is fully available through Craft's GraphQL API. All text metadata fields resolve to the correct language based on the queried site.

### Available fields

| Field | Type | Description |
|-------|------|-------------|
| `url` | `String` | Full image URL |
| `altText` | `String` | Alt text for the current site language |
| `description` | `String` | Image description for the current site language |
| `title` | `String` | Image title for the current site language |
| `credits` | `String` | Credits/attribution for the current site language |
| `rights` | `String` | Rights/licensing info for the current site language |
| `tags` | `[String]` | Tags for the current site language |
| `width` | `String` | Image width |
| `height` | `String` | Image height |
| `code` | `String` | Image code |
| `documentId` | `String` | Imageshop document ID |
| `data` | `String` | Raw JSON data |
| `resizedUrl(width: Int!, height: Int)` | `String` | Resized CDN URL at the given dimensions |
| `focalPoint` | `String` | JSON with `x`/`y` percentages (0–100), or `null` |

### Example query

```graphql
{
    entries(site: "english", section: "blog") {
        title
        ... on blog_default_Entry {
            heroImage {
                url
                altText
                description
                credits
                tags
                resizedUrl(width: 960)
                focalPoint
            }
        }
    }
}
```

### Multi-site language resolution

When you query entries for a specific site (e.g. `site: "norwegian"`), all text fields automatically return content in that site's language. No extra arguments are needed — the language is determined by the site context of the query.

```graphql
{
    en: entries(site: "english", section: "blog", limit: 1) {
        ... on blog_default_Entry {
            heroImage { altText }  # Returns English alt text
        }
    }
    no: entries(site: "norwegian", section: "blog", limit: 1) {
        ... on blog_default_Entry {
            heroImage { altText }  # Returns Norwegian alt text
        }
    }
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

To access it, go to **Utilities → Imageshop**, and click **Sync metadata**.

The sync runs in two phases:

1. **Fetch changes** — The plugin calls the Imageshop API to find all documents that have changed since the last sync. For each changed document, it fetches the latest metadata in every language present in your content (e.g. `en`, `no`, `sv`).
2. **Queue updates** — A queue job is created for each content row that contains an Imageshop field, updating stored metadata (alt text, description, credits, rights, tags, and title) with the freshly fetched API data.

Flash messages confirm how many jobs were queued, or report that no changes were found.

### Sync history

Each sync run is logged and displayed in a **Sync history** table directly on the utility page. The table shows:

| Column | Description |
|--------|-------------|
| Date | When the sync was triggered |
| Documents changed | Number of documents fetched from the API |
| Jobs queued | Number of content rows queued for update |
| Status | **Success** (jobs were created) or **No changes** (nothing to update) |

The log keeps the most recent 20 entries and prunes older ones automatically.

### When to use

* When an image in the Imageshop service has been updated since it was last added to the Craft CMS system.
* When you want to override any manual changes made to images (for example, manually edited alt text).

### Multi-language sync

The sync fetches document metadata once per language that exists in your content. This means all language variants (English, Norwegian, Swedish, etc.) are updated in a single sync run — not just the plugin's configured default language.

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

### Complete SEOmatic + Multi-Site Setup

The ImageShop plugin and SEOmatic each handle different parts of your page's social sharing metadata. Both must be configured for complete OpenGraph / Twitter Card output.

**Division of responsibility:**

| Meta Tag | Source | Configuration |
|----------|--------|---------------|
| `og:image` / `twitter:image` | ImageShop plugin | Plugin settings → OpenGraph field |
| `og:image:alt` | ImageShop plugin | Automatic from ImageShop API (per-language) |
| `og:image:width` / `og:image:height` | ImageShop plugin | Automatic from image data |
| `og:title` | SEOmatic | SEOmatic → Content → section → Title Source |
| `og:description` | SEOmatic | SEOmatic → Content → section → Description Source |
| `og:type` | SEOmatic | SEOmatic → Content → section → OG Type |
| `og:url`, `og:locale` | SEOmatic | Automatic from site/entry URL |

#### Configuring SEOmatic per section

In the control panel, go to **SEOmatic → Content SEO**, then click the section you want to configure (e.g. "Blog"):

1. **SEO Description Source** — set to "From Custom" and enter a Twig template referencing your description field, e.g. `{entry.summary}` or `{entry.description}`.
2. **SEO Title Source** — typically "From Field" using the entry title, or "From Custom" for a custom pattern.
3. **OpenGraph Title / Description** — set to "Same as SEO Title" / "Same as SEO Description" to inherit from the SEO settings above.
4. **OpenGraph Type** — use `article` for blog/news sections, `website` for homepage or static page sections.

Repeat for each section that should have social sharing metadata.

#### Multi-site configuration

SEOmatic stores its meta bundles **per site**. Each site needs its own configuration:

1. In **SEOmatic → Content SEO**, use the **site switcher dropdown** (top-left of the CP) to switch between sites.
2. Configure each section's SEO/OG settings for that site (title source, description source, etc.).
3. The ImageShop field must be set to **translatable** (per-site) so each site can have its own alt text and description. Alt text language is resolved automatically from the current site — no extra configuration is needed.

**URI format tip for translated sites:** If the translated site's `baseUrl` already includes a language prefix (e.g. `https://example.com/no/`), the section URI formats should **not** repeat the prefix. Use `blog/{slug}`, not `no/blog/{slug}`.

#### Verifying the complete setup

After configuring both plugins, check the rendered HTML to confirm all expected tags are present:

```bash
curl -s https://your-site.com/blog/example-post | grep -iE 'og:|twitter:'
```

For a blog post, you should see:

- `og:type` → `article`
- `og:title` → entry title (from SEOmatic)
- `og:description` → entry summary/description (from SEOmatic)
- `og:image` → ImageShop URL (from ImageShop plugin)
- `og:image:alt` → language-appropriate alt text (from ImageShop plugin)
- `og:url` → canonical URL (from SEOmatic)

If any tags are missing, check that the corresponding plugin (ImageShop or SEOmatic) is configured for that section and site.

## High-Quality Image Permalinks

The plugin provides an API endpoint for generating high-quality image URLs on-demand via the Imageshop Permalink API. This is useful for lightbox/modal popups where you want to show a higher resolution version than the default field image.

### How it works

Imageshop fields store images at the resolution configured in the field settings (e.g. 1920px wide). The permalink endpoint lets you request a larger version (default 3840px) from the Imageshop CDN, returning a permanent URL.

### Endpoint

```
GET /actions/imageshop-plugin/permalink/get-hq-url
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
            fetch('/actions/imageshop-plugin/permalink/get-hq-url?documentId=' + img.documentId)
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
