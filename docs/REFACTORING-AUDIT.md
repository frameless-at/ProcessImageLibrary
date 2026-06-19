# Refactoring audit (branch: feature/refactor)

Read-only audit of the whole module (PHP module + `src/` traits + JS client +
`assets/*.js` + CSS), scored for DRYness, best practice and maintainability.
Findings are grouped by priority. Line numbers are indicative (pre-refactor) and
should be re-confirmed when implementing. Nothing here is applied yet.

Scope: ProcessImageLibrary.module.php (7503), ProcessImageLibrary.js (5371),
ProcessImageLibrary.css (2045), ProcessImageLibraryConfig.php (458),
src/*.php (3215), assets/*.js (871).

---

## P1 — Correctness / security (verify, then fix)

> **Status: P1 complete.** 1 — `executeReplace` now validates upload content
> (`getimagesize()` / SVG sniff) and writes via temp-name + atomic rename
> (1.0.5). 2 — re-verified: no user-controlled basename is interpolated into a
> JS attribute selector any more; key lookups go through `rowElsByKey()`; the
> remaining interpolations carry safe charsets (field names, hashes, numeric
> ids). 3 — `reclaim-live.js` runs every server value through `esc()` / numeric
> `fmt()`; labels are static. 4 — pwimage grammar comments corrected. 5 —
> `buildFilters()` extracted, both readers share it. 6 — silent swallow-and-
> return-empty catches in the duplicate-cluster scan, usage count, usage stats,
> per-field usage scan and the version-assign subfield copy now log via
> `$this->wire('log')->error(...)`. 7 — both `@stat()` sites in `Hashing` guard
> `!$st` before reading keys. `executeWidget` GET-without-CSRF kept as the
> documented read-only exception.

1. **`executeReplace` accepts uploads on extension string match only**
   (`module.php:~2877`). No MIME/content check — an editor can overwrite an
   image's bytes with arbitrary content under an image extension. Validate via
   `getimagesize()` / PW `WireUpload` (also enforces allowed types). **High.**

2. **JS attribute-selector lookups interpolate user-controlled basenames**
   (`ProcessImageLibrary.js:~901,1185,4103,4536,4619,4825,4970`). A `data-key`
   carries the basename; a basename containing `"` breaks or mis-matches the
   selector. A safe `rowsByKey()` map already exists — route all key lookups
   through it. **Medium–High.**

3. **`reclaim-live.js` `audit()` builds a table from server JSON via innerHTML**
   (`assets/reclaim-live.js:~183-203`, also totals `~91-94,237`). If any field
   (`versionReasons` keys, human sizes) can carry path/filename text, it's an
   injection vector. Build with `textContent` / DOM nodes. **Medium.**

4. **pwimage embed grammar — comments were inverted (RESOLVED, comments only).**
   Confirmed the ground truth from PW core `ProcessPageEditImageSelect`: an
   inserted variation is stored in the SOURCE image's own files folder, so the
   URL `/files/<sourcePid>/<stem>...` has the source page id as its directory for
   BOTH same- and cross-page inserts; the optional `-pid<N>` marker records the
   EDITING page that USES the variation, not the source. So `extractUsageKeys`
   (which keys on the directory pid) was already correct, and so is the active
   matching in `findImageReferences` / `rewriteEmbeddedReferences` (their DIRECT
   branch matches the source-folder URL). The "conflict" was only (a) inverted
   doc comments in the rename/delete family AND a self-contradictory docblock in
   `extractUsageKeys`, and (b) a defensive cross-branch + `renameCrossPageCopies`
   that target a rarer non-standard setup and are no-ops for standard data.
   **Verified by the user: renaming an embedded image works.** Fix applied:
   corrected all six comment sites to the core truth and documented the grammar
   on the `ImageLibraryUsage` trait; behaviour unchanged, defensive fallback
   kept. (A shared grammar HELPER extraction is still open — do it with an
   explicit cross-page test; see P2.) **Status: resolved.**

5. **`parseFilterQs` (`module.php:~4817`) and `readFilterInput` (`~5104`) are
   ~95% duplicated.** They must stay byte-identical for the match-aware
   "row vanished" check to be correct; drift is a real bug class. Extract a
   single `buildFilters(array $params)`. **High.**

6. **Silent empty `catch (\Throwable $e) {}`** swallowing DB/FS faults across
   `ImageLibraryHashing` (`~141,187,224,246`), `ImageLibraryUsage` (`~456`) and
   the main module (`~1254,2533,3530,3558`). A genuine SQL/permission error
   becomes an invisible "no duplicates / no usage" — the worst failure mode for
   an audit tool. Log at debug minimum. **Medium.**

7. **Unguarded `@stat(...)['dev']`** in `diskAudit` (`Hashing.php:~737`) — emits a
   warning / yields 0 if the file vanished mid-walk; `expandAllSharedStep`
   already guards this, `diskAudit` does not. **Low–Medium.**

8. **Inconsistent casts in the NUL identity key** (see P2.1) are a latent
   correctness issue, not only DRY.

> Verify first: `executeWidget` was flagged for lacking a CSRF/POST guard
> (`module.php:~2653`); it is a read-only GET render, so GET-without-CSRF is
> defensible — decide whether to keep it as the documented exception or align it.

---

## P2 — High-impact DRY (shared helpers, low risk)

> **Progress (done):** P2.1 PHP `hashKey()` (18 NUL-key sites unified);
> P2.2 `beginJsonPost()` (15 POST endpoints); stem via `basenameStem()` +
> `splitImageTags()` (10 sites); P2.3a JS `postForm()` (11 POST sites);
> CSS `--ml-*` tokens (mono/danger/confirm/tint-hover).
> **Deferred:** the JS **dialog builder** (6 bespoke `<dialog>` constructions
> with their own focus/teardown) — too risky to unify blind without a runtime
> pass; do it with manual modal testing. JS key helpers (`keyOf/parseKey`)
> partially covered by `rowElsByKey()`; full consolidation still open.

1. **Identity-key helpers.** The NUL-joined key
   `pageId."\0".field."\0".basename` is hand-built ~12× in `module.php` and
   ~13× across the traits (~25 total), with inconsistent `(int)`/`(string)`
   casts; `rowKey()` (colon form) exists but there is no NUL-form helper. Add
   `hashKey(int,string,string)` (+ a 2-arg variant) and route everything
   through it / `rowKey()`. JS side: `keyOf()`, `parseKey()`, `rowForKey()`.

2. **PHP AJAX preamble + POST/CSRF guard.** `$config->ajax=true` + JSON header +
   `ob_start()` + POST-method + CSRF is copy-pasted in ~10 endpoints
   (`module.php:~991-1129` and on). Extract `beginJsonPost(): ?string`. Also
   replace the hand-rolled `$_SERVER['REQUEST_METHOD']` test with
   `$input->requestMethod('POST')`.

3. **JS `postForm()` + dialog builder.** The fetch+FormData+CSRF+`X-Requested-With`
   POST is reimplemented ~8× in `ProcessImageLibrary.js` and again in
   `reclaim-live.js` (×4). Six near-identical `<dialog>` builders +
   `library-pick.js`'s fallback. Add `postForm(url,payload,{wantStatus})`,
   `buildDialog({title,body,buttons})`, `mkButton(label,variant)`, `mkIcon(name)`.
   Ideally in a shared `ml-dom.js` loaded by all six JS files.

4. **Shared picker-message lifecycle.** `insert-common.js onMessage` and
   `library-pick.js onModalMsg` are the same origin-check → mlCancel/mlInsert/
   mlPicked → cleanup lifecycle. Extract `MLImageLibrary.onPickerMessage()`.

5. **Insert-adapter triplication.** `cfg()`, `labels()`, and the
   `open→openPicker` glue are duplicated between `insert-mce.js` and
   `insert-cke.js`; collapse into `insert-common.js` helpers so the adapters
   stay thin.

6. **Tag splitting.** `splitTags()` exists but is bypassed by inline
   `preg_split('/\s+/', …)` at `module.php:~3971,3977,4685,6664` (resolve the
   whitespace-vs-comma distinction while doing it).

7. **Stem/extension splitting** open-coded 6+ times across module + traits; a
   `basenameStem()` helper already exists in `ImageLibraryUsage` — route all
   sites through it.

8. **CSS design tokens.** Repeated `color-mix(...)` hover/active tints (~8×, with
   drifted `60%/6%` vs `60%/8%` and fallback colours), danger/confirm reds+green
   (`#c33`/`#c0392b`/`#2a8a2a`, ~10×), the monospace stack (6×), and the curate
   cursor SVGs (2 unique, duplicated 4×). Hoist to custom properties
   (`--ml-tint-hover`, `--ml-tint-active`, `--ml-tint-selected`, `--ml-danger`,
   `--ml-confirm`, `--ml-mono`, `--ml-cursor-add/remove`). Add a
   `.ml-hover-reveal` utility for the 6× `opacity:0;transition:opacity .12s`.

9. **Smaller PHP helpers:** `ariaLabel($fmt,...)` (~9× doubled
   sprintf+entities in renderTable), a debug-row helper (`renderDebug` ~8×),
   `sanitizeTagToken()` (tag charset regex ×3), `pageDisplayName(Page)`
   (title-or-name idiom), `customDateTs()`/`optionIds()` (custom value readers).

---

## P3 — Maintainability (decompose the giants)

Methods/functions far over a ~80-line guideline:

PHP
- `renderTable` (~516, `module.php:6691`) → `renderTableHead/renderRow/renderThumbCell/renderCustomCell/thumbDisplayDims`. Unblocks several P2 DRY items.
- `executeBulk` (~290, `3770`) → extract `resolveBulkTagValue/resolveBulkAddValue`; share with save/rename.
- `executeSave` (~177, `2431`) → `saveTypedCustom/normalizeTagValue/buildSaveResponse`.
- `___execute` (~155, `1368`) → `renderRootAttributes/renderRootStyleVars/renderChrome`.
- `renderFilterBar` (~200, `6421`) → `addSearchRow/addTagsFieldset/addMissingCheckboxes`.
- `renderMasonry` (~133), `executeAssign` (~142), `renderDebug` (~168).
- `Config::getInputfields` (~390) → per-fieldset builders; move the side-effecting GET revert action out of the render path.
- `ImageLibraryExportImport::executeImport` (~165), `executeExport` (~108).

JS
- `commit(mode)` (~480, `ProcessImageLibrary.js:1324`) inside `activateEditor`
  (~620) → `commitFilename/commitBatch/commitSingle` + a thin dispatcher.
- The collections tree (~840, `4081-4924`) → peel into a `collections.js` with a
  pure tree model (`collFlatten/collNest/collMove/...`) + UI controller; rename
  the opaque 1-char locals.
- `runBatchSave({cells,apply,revert,payload})` to unify the 3 batch-save branches.
- `buildTagChip` (~135) → `TagChipManager` factory; `inlineConfirm()` shared with
  the collections delete-confirm.
- Hoist the per-call bar builders out of `rerenderBookmarksList` (defined on
  every call).

`assets`
- `insert-common.js openNativeResize` (~150) and `reclaim-live.js run` (~100):
  decompose the nested-closure god-functions into module-scope helpers.

Structural: `ProcessImageLibrary.js` is one 5371-line IIFE with a ~5350-line
`init()`; logical seams are already marked by banner comments. Target ~9 modules
(core/fetch, inline editor, picker, dialogs, re-render+selection, filter bar,
view prefs, bookmarks/collections, export/import) sharing one namespace object,
replacing the `root._mlXxx` bridge.

---

## P4 — Cleanup (low risk, do alongside the above)

- **Dead code:** the personal-`collections` store paths in JS (the shared arrays
  are the real store; `collections` is always `[]` — `findCollection`,
  add/removeSelectionFromCollection, migrate, bar builders carry dead branches);
  the always-null `$dhash` parameter plumbing through 4 `Hashing` methods; unused
  locals (`$sanitizer` at `4093/4169`, `$collDelTitle` at `6295`,
  `hasActiveFilter` wrapper); commented-out CSS declaration (`css:718`).
- **Orphaned / contradictory docblocks** in `module.php` (around `237`, `451`,
  `4061`, `4577`, `4955`) and `ImageLibraryExportImport.php` (`~362-372` doc
  split mid-sentence) — re-pair docblocks with their methods.
- **CSS focus suppression** (`css:971-983`) strips all focus rings module-wide and
  contradicts the `:1649` "show a focus ring" comment — scope it and restore a
  `:focus-visible` ring (accessibility).
- **CSS organization:** add per-region section banners + a top TOC; group the
  three `@media (max-width:640px)` and ten `@media (hover:…)` blocks into one
  responsive/input-capability section.
- **Comment/code mismatches:** the "ES5-ish" header on a file using
  `Map/Set/URL/<dialog>/padStart`; the `assignKey` "basenames may contain ':'?"
  uncertain comment.
- **Define an `ImageLibraryHost` interface** the six traits rely on (today the
  host-method contract is prose-only and only fails at runtime).

---

## Suggested sequencing

1. P1 security/correctness items (small, independently shippable).
2. P2 shared helpers — land the helper, then migrate call sites in batches
   (keeps diffs reviewable; each migration is mechanical + lint-verified).
3. P3 decomposition, one giant method at a time, behind the now-shared helpers.
4. P4 cleanup folded in opportunistically.

Each step: `php -l` / `node --check` / CSS brace-balance after every change;
the module has no test suite, so changes must stay behaviour-preserving and be
verified on the remote.
