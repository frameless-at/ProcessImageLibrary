# Known issues

## Safari: reorder arrows stick visible after a mouse click (columns picker)

**Status:** open for the COLUMNS picker · resolved for the collections /
bookmarks manager · **Browsers:** Safari only (Chrome/Firefox OK)

> Update: the collections / bookmarks **manager** now drives its row reveal from
> `.ml-coll-row:focus-within` and, after an up/down/nest/unnest reorder,
> deferred-focuses the MOVED row's button (rAF) so the highlight + controls
> follow the moved element to its new position instead of the row that slid into
> its old slot. The "sticky" focus is now the intended behaviour there. The
> Columns picker still uses the old `:focus-visible` pattern below.

The hover-revealed reorder arrows in the Columns picker (`.ml-col-move`) and
the Collections manager (`.ml-coll-move`) are shown via the same pattern as the
working tag-manager chip controls:

```css
.ml-col-move { opacity: 0; }
<container>:hover .ml-col-move,
.ml-col-move:focus-visible { opacity: 1; }
```

In Chrome/Firefox a mouse click does not match `:focus-visible`, so the arrow
hides again when the pointer leaves. **Safari** applies `:focus-visible` to a
`<button>` after a plain mouse click (and keeps it through the programmatic
`.focus()` the columns reorder handler calls), so the clicked arrow stays
visible until focus moves elsewhere.

Already mitigated: on dialog open we `blur()` the auto-focused control, so the
first row no longer reveals on open. The remaining case is *after a click*.

**Likely fix (when we get to it):** stop relying on `:focus-visible` for these
buttons — drive the reveal from JS (`mouseenter`/`mouseleave`) and/or explicitly
`blur()` the button at the end of each reorder click handler. Keyboard
accessibility must be preserved (tabbing to a button should still reveal it).
