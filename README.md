# Media Library

A ProcessWire module that displays all images from the entire PW installation in a single table. Editors can edit image metadata across all pages inline—description, tags, custom subfields—without having to navigate to each page individually.

See [MediaLibrary-Konzept.md](MediaLibrary-Konzept.md) for the design document.

## Status

Phase 8 — work in progress. AJAX re-render of the results region: filter submit, sort and pagination swap the table in place without a full reload, browser back/forward replays via popstate. Column-driven sort, inline edit (description, tags, text custom fields), WireCache reads with template-based invalidation, filter bar (full-text search, template / field selects, missing-X toggles, galleries-only). Richer custom-field input types and bulk operations in later phases.

## Requirements

- ProcessWire 3.0.172+
- PHP 8.0+

## Install

Copy the repository contents into `site/modules/ProcessMediaLibrary/`, then install via the ProcessWire admin (Modules → Refresh → Install „Media Library").

The module creates an admin page under Setup → Media Library and a `media-library-access` permission.

## License

MIT — see [LICENSE](LICENSE).
