# Imageshop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).


## 3.0.0 - 2026-05-21
### Added
Updating packagist setup and release for main to support craft 4 and craft 5.

## 2.6.0 - 2026-04-20
### Added
- **Per-site language mapping.** New plugin setting "Site language mappings" lets editors override which Imageshop language code is used for each Craft site. Example: a Norwegian Bokmål (`nb-NO`) Craft site can be mapped to pull Nynorsk (`nn`) texts from Imageshop. Empty entries fall back to the auto-derived code (existing behavior). Applies to the picker `CULTURE`, admin field labels (`data-current-language`), `ImageShop` model text getters (`getAltText()`, `getDescription()`, `getTitle()`, `getCredits()`, `getRights()`, `getTags()`), and all GraphQL text resolvers.
- `services\ImageShop::getImageshopLanguageForSite(?Site $site)` resolver: checks the per-site mapping → falls back to `sanitizeLanguage($site->language)` → falls back to the global `$settings->language`.
- `models\Settings::$siteLanguages` array property (keyed by Craft site handle), exposed in the settings UI as one text input per site with the auto-derived code as placeholder.
- Optional `$lang` parameter on `models\ImageShop::getAdminLabel($lang = null)`.

### Fixed
- Admin field card title now respects the site's resolved Imageshop language. Previously, after picking an image, the AJAX re-render path (`ContentController::actionGetImageList`) built fresh `ImageShop` models without a site language, so `getAdminLabel()` fell back to the CP's current-site language (often English) instead of the entry site's mapped Imageshop language. The controller now calls `setSiteLanguage()` on each model and `input-list.twig` passes the template's `language` variable explicitly to `getAdminLabel()`.

### Changed
- `ImageShopField::getCurrentAdminLanguage()` and `ImageShopField::normalizeValue()` now route through the new `getImageshopLanguageForSite()` resolver instead of calling `sanitizeLanguage()` directly on the site language.
- `models\ImageShop::getLang()` defaults to the resolver when neither an explicit `$lang` nor a pre-set `_siteLanguage` is available, so models constructed outside `normalizeValue()` still honour the per-site mapping.

## 2.5.1 - 2026-04-16
### Security
- The persistent Imageshop API token is no longer exposed in the picker popup URL. Previously `IMAGESHOPTOKEN` was rendered server-side into the entry edit page, leaking the long-lived token to browser history, devtools, referer headers and proxy logs. The field now requests a fresh short-lived token from a new CSRF-protected CP action (`imageshop-dam/picker/get-url`) just before the popup opens, so the long-lived token never leaves the server. Requires no configuration changes.

### Added
- `PickerController::actionGetUrl` CP action that mints a picker URL with a short-lived token. Requires `accessCp` permission and a valid CSRF token.
- `services\ImageShop::getPickerUrl($options)` helper that whitelists picker options and builds the popup URL with a fresh temporary token.

### Changed
- `services\ImageShop::getTemporaryToken()` now explicitly returns `?string` and parses the API response (handles both raw string and JSON-encoded string shapes).
- `ImageShopField::getInputHtml()` no longer builds the picker URL; it passes non-sensitive `pickerOptions` to the field JS instead.
- Field JS (`showPopup`) now fetches the picker URL on click via AJAX and displays an error notification if the token cannot be obtained.

## 2.5.0 - 2026-04-07
### Added
- **Craft CMS 5 support.** The plugin now works on both Craft 4 and Craft 5. Composer requirement updated to `^4.0.0 || ^5.0.0`.
- Restored **"Edit description before insert?"** (`showDescription`) field setting and `SHOWDESCRIPTION` picker popup parameter. When enabled, the Imageshop popup shows a description field that editors can fill in before inserting the image. The entered description is passed through to Craft and pre-populates the description field on the entry.
- **Site-aware picker language.** The Imageshop popup (`CULTURE` parameter) now uses the current site's language instead of the plugin's global language setting. Editors see the popup in the correct language when editing entries on different sites.
- **Smaller admin thumbnails.** Field thumbnails in the control panel now load 400px resized images via `getResizedUrl()` instead of full-size originals, reducing bandwidth and improving editor performance.
- `MEDIUMTEXT` content column type for ImageShop fields (was `TEXT`), supporting larger JSON payloads for gallery fields with many images. Includes a migration to upgrade existing field columns.
- `refreshMetadata` controller action for re-fetching document metadata from the Imageshop API after popup selection.

### Fixed
- Fixed Craft 5 compatibility: updated `normalizeValue()`, `serializeValue()`, and `getInputHtml()` method signatures. Added static `dbType()` method alongside `getContentColumnType()`.
- Fixed Craft 5 utility registration: `EVENT_REGISTER_UTILITY_TYPES` → `EVENT_REGISTER_UTILITIES` (runtime detection for dual Craft 4/5 support).
- Fixed Craft 5 service registration: added `config()` static method for component registration.
- Fixed `normalizeValue()` to handle arrays of JSON strings and decoded associative arrays (Craft 5 content storage path).
- Added `public` visibility modifier to utility methods (`id()`, `contentHtml()`) and queue job `execute()` methods.

### Changed
- Composer requirement updated from `^4.0.0` to `^4.0.0 || ^5.0.0`.

## 2.4.0 - 2026-02-20
### Added
- Focal point support: `getFocalPoint()` on the image model returns CSS-ready `x`/`y` percentages from the Imageshop picker's focal point data.
- `focalPoint` field in GraphQL type, returning JSON with `x`/`y` percentages.
- `altText` and `tags` fields in GraphQL type.
- Explicit GraphQL resolvers for all language-dependent text fields (`credits`, `description`, `title`, `altText`, `rights`, `tags`), ensuring correct per-site language resolution.
- Documentation for focal point usage in Twig templates and GraphQL.
- High-quality image permalink endpoint (`/actions/imageshop-dam/permalink/get-hq-url`) for fetching larger resolution images on-demand via the Imageshop Permalink API. Useful for lightbox/modal popups.
- `getPermalink()` service method for generating permanent CDN URLs at any resolution.
- Documentation for the permalink endpoint and gallery lightbox usage pattern.
- Full Norwegian (Bokmål) translations for all plugin UI: settings page, field settings, field input labels, utility page, and queue job descriptions.
- Added `|t('imageshop-dam')` translation filters to all plugin templates (settings, field settings, field input) to enable localization.
- Sync log table (`imageshop-dam_sync_log`) records each sync run with documents changed, jobs queued, and status.
- Sync history displayed on the Utilities → Imageshop page.

### Fixed
- SEOmatic OpenGraph/Twitter image integration now works correctly. The matched element is resolved inside the event handler instead of at plugin init time, where routing hasn't completed yet. Image dimensions and alt text are now also set on the meta tags.
- Fixed "Attempt to assign property on null" error when SEOmatic meta object is not initialized on pages without a matched element (e.g. listing pages).
- SEOmatic CP sidebar preview (SEO Preview) now shows the Imageshop image in the Twitter and Facebook card previews.
- GraphQL queries now return text in the correct site language instead of always using the current site's language.
- `allowMultiple` setting is now enforced — single-image fields no longer accumulate extra images when the picker is opened repeatedly.
- Sync now fetches metadata for all languages found in content, not just the plugin's configured language.
- Fixed `mapDocumentFields` reading text fields from wrong location in API response.
- Sync job uses correct field column names.
- Fixed swapped descriptions for "Show Crop Dialogue" and "Show Size Dialogue" field settings.
- Fixed duplicate `title` attribute on reorder icon in field input.
- Fixed typos in OpenGraph global settings instructions.

### Changed
- Added CSRF validation and POST method enforcement on controller actions.
- Hardened API service layer: validate responses, prevent cache poisoning via document ID validation, use environment variable for API base URL.
- Added primary key to sync table via migration.
- Updated plugin logo/branding.
- Removed unused `UpdateFields` job and orphaned migration.
- Removed empty `EVENT_AFTER_INSTALL_PLUGIN` handler.
- Standardized branding from "ImageShop" to "Imageshop" across all display text, documentation, and translations.
- Reduced default permalink image width from 3840px to 1920px for faster loading.
- Removed `showDescription` and `showCredits` field settings and their corresponding picker popup parameters (`SHOWDESCRIPTION`, `SHOWCREDITS`). Existing project configs with these keys are handled gracefully during upgrade.

## 2.3.0 - 2025-11-11
### Added
- Added an option to override image alt text and description.
- Added the functionality of sorting images in the field.
- Fixed "Sync metadata" utility.
- Added the option to use imageshop images foropengraph, with SEOmatic plugin.

## 2.1.0 - 2024-12-19
### Added
- Sync utility to update existing fields.

## 2.0.7 - 2024-11-01
### Fixed
- GraphQL now returns an array of imageShop images.

## 2.0.6 - 2024-09-05
### Added
- Multi image support

## 2.0.5 - 2024-01-25
### Added
- Data to GraphQL

## 2.0.4 - 2023-12-19
### Fixed
- GraphQL bug fix

## 2.0.3 - 2023-12-03
### Added
- GraphQL support

## 2.0.2 - 2023-03-07
### Added
- Show Description option
- Show Credits option

### Changed
- default lang based off plugin settings

## 2.0.1 - 2022-12-12
### Fixed
- Styling issues
- Fieldtype fixes

## 2.0.0 - 2022-10-11
### Added
- Initial release
