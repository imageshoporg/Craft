# Imageshop Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 2.4.0 - 2026-02-20
### Added
- Focal point support: `getFocalPoint()` on the image model returns CSS-ready `x`/`y` percentages from the Imageshop picker's focal point data.
- `focalPoint` field in GraphQL type, returning JSON with `x`/`y` percentages.
- `altText` field in GraphQL type.
- Documentation for focal point usage in Twig templates and GraphQL.
- High-quality image permalink endpoint (`/actions/imageshop-dam/permalink/get-hq-url`) for fetching larger resolution images on-demand via the Imageshop Permalink API. Useful for lightbox/modal popups.
- `getPermalink()` service method for generating permanent CDN URLs at any resolution.
- Documentation for the permalink endpoint and gallery lightbox usage pattern.

### Fixed
- SEOmatic OpenGraph/Twitter image integration now works correctly. The matched element is resolved inside the event handler instead of at plugin init time, where routing hasn't completed yet. Image dimensions and alt text are now also set on the meta tags.
- Fixed "Attempt to assign property on null" error when SEOmatic meta object is not initialized on pages without a matched element (e.g. listing pages).
- SEOmatic CP sidebar preview (SEO Preview) now shows the Imageshop image in the Twitter and Facebook card previews.
- GraphQL queries now return text in the correct site language instead of always using the current site's language.
- `allowMultiple` setting is now enforced — single-image fields no longer accumulate extra images when the picker is opened repeatedly.
- Sync job uses correct field column names.

### Changed
- Added CSRF validation and POST method enforcement on controller actions.
- Hardened API service layer: validate responses, prevent cache poisoning via document ID validation, use environment variable for API base URL.
- Added primary key to sync table via migration.
- Updated plugin logo/branding.
- Removed unused `UpdateFields` job and orphaned migration.
- Removed empty `EVENT_AFTER_INSTALL_PLUGIN` handler.
- Standardized branding from "ImageShop" to "Imageshop" across all display text, documentation, and translations.

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
