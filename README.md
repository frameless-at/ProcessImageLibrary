# Media Library

A ProcessWire module that displays all images from the entire PW installation in a single table. Editors can edit image metadata across all pages inline—description, tags, custom subfields—without having to navigate to each page individually.

See [MediaLibrary-Konzept.md](MediaLibrary-Konzept.md) for the design document.

## Status

Phase 5 — work in progress. Read pipeline cached via WireCache with template-based invalidation. Server-rendered table with filter bar and pagination, including per-custom-field "Missing X" toggles. Column-driven sort, AJAX re-render, inline edit land in later phases.

## Requirements

- ProcessWire 3.0.172+
- PHP 8.0+

## Install

Copy the repository contents into `site/modules/ProcessMediaLibrary/`, then install via the ProcessWire admin (Modules → Refresh → Install „Media Library").

The module creates an admin page under Setup → Media Library and a `media-library-access` permission.

## License

MIT — see [LICENSE](LICENSE).
