# Media Library

A ProcessWire module that displays all images from the entire PW installation in a single table. Editors can edit image metadata across all pages inline—description, tags, custom subfields—without having to navigate to each page individually.

See [MediaLibrary-Konzept.md](MediaLibrary-Konzept.md) for the design document.

## Status

Phase 7 — work in progress. Column-driven sort via header clicks (asc/desc, sort+dir persist across filter and pagination). Inline edit for description, tags, and text-typed custom-fields-on-images. Reads are cached via WireCache and auto-invalidate on save. Filter bar with full-text search, template / image-field selects, missing-X toggles (incl. per custom field) and galleries-only. AJAX re-render and richer custom-field input types in later phases.

## Requirements

- ProcessWire 3.0.172+
- PHP 8.0+

## Install

Copy the repository contents into `site/modules/ProcessMediaLibrary/`, then install via the ProcessWire admin (Modules → Refresh → Install „Media Library").

The module creates an admin page under Setup → Media Library and a `media-library-access` permission.

## License

MIT — see [LICENSE](LICENSE).
