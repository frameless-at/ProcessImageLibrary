# Media Library

A ProcessWire module that displays all images from the entire PW installation in a single table. Editors can edit image metadata across all pages inline—description, tags, custom subfields—without having to navigate to each page individually.

See [MediaLibrary-Konzept.md](MediaLibrary-Konzept.md) for the design document.

## Status

Phase 10b — work in progress. Bulk edit as paintbrush: tick rows, then edit any cell on a selected row — the change broadcasts to all selected rows via the same widget (whitelist tags, free-form tags with autocomplete, text, textarea). Delete remains as an explicit action with confirm. Per-page batching server-side so each page is saved at most once per field touched. Whitelist validation respected per item. AJAX re-render with pushState/popstate, column-driven sort, inline edit (description, tags, text custom fields), WireCache reads with template-based invalidation, filter bar (search, template / field selects, missing-X toggles, galleries-only). UI polish in a combined later pass.

## Requirements

- ProcessWire 3.0.172+
- PHP 8.0+

## Install

Copy the repository contents into `site/modules/ProcessMediaLibrary/`, then install via the ProcessWire admin (Modules → Refresh → Install „Media Library").

The module creates an admin page under Setup → Media Library and a `media-library-access` permission.

## License

MIT — see [LICENSE](LICENSE).
