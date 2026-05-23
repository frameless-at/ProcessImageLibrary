# MediaLibrary Module — Konzept

**Ziel-Repo:** https://github.com/frameless-at/media-library

## Ziel

Ein ProcessWire-Modul, das **alle Bilder einer PW-Installation** in einer einzigen Tabelle zeigt — eine zentrale Medien-Bibliothek-Sicht. Editoren bearbeiten Bild-Metadaten quer durch alle Pages und Image-Felder inline (Description, Tags, Custom-Subfields), ohne pro Page navigieren zu müssen.

**Primärer Use-Case:** Content-lastige Site mit tausenden Bildern über dutzende oder hunderte Pages verteilt, viele mit fehlender Description oder unvollständigen Tags. Editor will filtern (z.B. „nur Bilder ohne Description"), abarbeiten, das nächste Filter wechseln — ohne ständig zwischen Page-Edit-Screens zu springen.

## Scope

**In-Scope:**

- Aggregiert Bilder aus **allen** `FieldtypeImage`-Feldern auf **allen** Templates der Installation
- Jede Tabellen-Zeile = Tupel `(page, fieldName, basename)`
- Inline-Edit der editable Subfields (Description, Tags, Custom-Felder)
- Server-side Filter / Sortierung / Pagination
- Spalten-Konfiguration per User
- Auto-Erkennung von **Custom Fields on Images** (`field-{fieldname}`-Template, PW 3.0.142+)

**Out-of-Scope (Phase 1):**

- Bilder hochladen / löschen / verschieben zwischen Pages
- Bulk-Edit per Multi-Select
- Variations-Management (Crop / Focus / Re-Generate)
- Mehrsprachige Subfields
- Re-Sort innerhalb eines Image-Felds (`$img->sort`)

## Architektur

**Modul-Typ:** `Process`-Modul, eigene Admin-URL `/processwire/setup/media-library/`.

**Klassen:** `ProcessMediaLibrary`

**Dateistruktur:**

```
ProcessMediaLibrary/
├── ProcessMediaLibrary.module.php
├── ProcessMediaLibrary.info.json
├── ProcessMediaLibrary.css
├── ProcessMediaLibrary.js
├── README.md
└── LICENSE
```

**Methods:**

- `___execute()` — Tabelle + Filter-UI rendern (Server-rendered HTML; JS hydratisiert für Interaktion)
- `___executeData()` — AJAX-GET, returnt JSON: paginated rows + total-count + filter-summaries
- `___executeSave()` — AJAX-POST, validiert + speichert eine Cell-Änderung, returnt JSON
- `___install()` / `___uninstall()` — Admin-Page-Lifecycle

## Datenmodell (PW-nativ)

Jede Tabellen-Zeile ist identifiziert durch das Tupel `(pageId, fieldName, basename)`.

**Listing-Pipeline (Read-Pfad):**

1. **Field-Discovery beim Boot:**

   ```php
   $imageFields = [];
   foreach (wire('fields') as $f) {
       if ($f->type instanceof FieldtypeImage) $imageFields[] = $f->name;
   }
   ```

2. **Selector aufbauen** auf Page-Ebene mit allen Filtern, die PW-Selector kennen:

   ```php
   $selector = "template=" . implode('|', $eligibleTemplates) . ", status<=hidden";
   if ($missingDescription) $selector .= ", images.description=";
   if ($templateFilter)     $selector .= ", template=$templateFilter";
   ```

3. **`$pages->findRaw()`** holt die kompletten Image-Daten in einem Schwung ohne Page-Object-Hydratation:

   ```php
   $rawData = $pages->findRaw($selector, array_merge(
       ["id", "title", "url", "templates_id"],
       array_map(fn($f) => "$f.basename",    $imageFields),
       array_map(fn($f) => "$f.description", $imageFields),
       array_map(fn($f) => "$f.tags",        $imageFields),
       array_map(fn($f) => "$f.filesize",    $imageFields),
       // plus auto-discovered custom subfields pro Feld
   ));
   ```

4. **Flatten in PHP** zu Image-Row-Liste:

   ```php
   $rows = [];
   foreach ($rawData as $pageId => $pageData) {
       foreach ($imageFields as $fieldName) {
           foreach ($pageData[$fieldName] ?? [] as $img) {
               $rows[] = [
                   'pageId'    => $pageId,
                   'fieldName' => $fieldName,
                   'basename'  => $img['basename'],
                   // … restliche Subfields …
               ];
           }
       }
   }
   ```

5. **Image-Level-Filter in PHP** (für Filter, die PW-Selector nicht auf der Subfield-Ebene exakt machen kann):

   ```php
   $rows = array_values(array_filter($rows, $userFilterFn));
   ```

6. **Sort + Slice** für die Pagination:

   ```php
   usort($rows, $sortFn);
   $total = count($rows);
   $slice = array_slice($rows, $offset, $limit);
   ```

7. **Thumbnail-URLs für die 50-Row-Slice laden** (nur jetzt echte `Pageimage`-Objects):

   ```php
   $uniquePageIds = array_unique(array_column($slice, 'pageId'));
   $pagesById     = $pages->getMany($uniquePageIds);
   foreach ($slice as &$r) {
       $page = $pagesById->get($r['pageId']);
       $img  = $page->{$r['fieldName']}->getFile($r['basename']);
       $r['thumbUrl']  = $img->size(120, 80)->url;
       $r['pageUrl']   = $page->url;
       $r['pageTitle'] = $page->title;
   }
   ```

→ Nur die **angezeigten 50 Bilder** triggern echte `Pageimage`-Loads für Thumb-URLs. Der Rest ist `findRaw`-Datenarray.

**Caching:**

`findRaw`-Resultat wird gecacht mit **selektiver Template-Invalidation**:

```php
$key    = "media-library-raw";
$cached = wire('cache')->get($key);
if (!is_array($cached)) {
    $cached = $pages->findRaw($selector, $fields);
    wire('cache')->save($key, $cached, "template=" . implode('|', $eligibleTemplates));
}
```

PW invalidiert den Cache automatisch wenn eine Page mit einem dieser Templates gespeichert wird — also auch die eigenen Cell-Saves via Save-Endpoint. Cache ist immer konsistent ohne manuellen Invalidations-Code.

**Save-Pfad (pure PW-API):**

```php
$page = $pages->get($pageId);
$img  = $page->{$fieldName}->getFile($basename);
$img->{$subfield} = $value;
$page->save($fieldName);  // löst die Cache-Invalidation aus
```

**Custom-Field-Discovery pro Image-Feld:**

```php
$customTpl = $templates->get("field-{$fieldName}");
if ($customTpl) {
    foreach ($customTpl->fields as $cf) {
        // Spalte für $cf->name auto-anlegen, in findRaw-Fields mit aufnehmen
    }
}
```

## Performance-Erwartungen

Alle Phase-1-Pfade verwenden ausschließlich PW-API. Erwartete Latenzen bei einem Dataset von ~3500 Bildern über ~40 Pages:

| Operation | Kalt (Cache miss) | Warm (Cache hit) |
|---|---|---|
| `findRaw` Multi-Field-Subfield-Query | ~80–150 ms | gecacht: ~10 ms |
| PHP-Filter über ~3500 Rows | ~10–20 ms | gleich |
| Sort + Slice (50 Rows) | <5 ms | gleich |
| `getMany` für ~30 unique Pages | ~50 ms | gleich (Pageimage-Loads) |
| Thumb-URLs (bei bereits gecachten Varianten) | <30 ms für 50 | gleich |
| **Gesamt-Listing-Request** | **~200 ms** | **~100 ms** |
| Save-Request (single cell) | ~150–200 ms | gleich |

**Skalierungs-Ausblick** (Phase 2 wenn nötig): bei Datasets jenseits ~10k Bildern auf `$pages->findMany()` umstellen — lazy-loading-Iterator, der Pages chunkweise streamt und Memory unter Kontrolle hält. Cache-Format dann ggf. auf einen per-Image-Key wechseln statt das volle Array zu serialisieren.

## Standard-Spalten

Pflicht / read-only:

- **Thumb** (`$img->size(120, 80)->url`), `loading="lazy"`
- **Page** (Title + Link zur PW-Edit-Seite)
- **Field** (Field-Name, z.B. `images`, `lead_image`)
- **Filename** (`$img->basename`)

Default-sichtbar / editable:

- **Description** — Textarea
- **Tags** — Input je nach `useTags`-Konfig des jeweiligen Felds:
  - `useTags=0`: Spalte verbergen
  - `useTags=1`: Text-Input + Autocomplete aus historisch verwendeten Tags
  - `useTags=2`: Multi-Select aus `tagsList`
  - `useTags=8|9`: Multi-Select + freier Text-Input
- **Dimensions** (`{w}×{h}`) — read-only
- **Filesize** — read-only

Auto-entdeckt (alle Custom-Field-Subfields des `field-{fieldname}`-Templates):

- Input-Typ-Mapping basierend auf der Inputfield-Klasse:
  - Text/Textarea → editable text/textarea
  - Checkbox → Checkbox
  - Page-Reference → Select / Multi-Select
  - Datetime → Date-Picker

**Konfig:** Per User in `$user->meta('mediaLibraryColumns')`. Default-Set: Pflicht + Description + Tags + Custom-Fields.

## Edit-Semantik

**Inline-Edit pro Zelle:**

1. Klick auf Zelle → Input/Textarea ersetzt Display-Wert
2. Blur ODER Enter → AJAX-POST mit `{ pageId, fieldName, basename, subfield, value }`
3. Server: validiert (Tag-Whitelist wenn `useTags=2`, etc.), führt `$page->save()` aus, returnt `{ ok, value }`
4. UI: optimistic update, grüner Check / rotes X mit Error-Tooltip bei Fehler
5. Cache wird durch das `$page->save()` automatisch invalidiert

**Save-Queue:** Mehrere Edits werden seriell geschickt — keine parallelen `$page->save()`-Aufrufe auf derselben Page (vermeidet ChangeTracker-Races).

## Filter

UI-Bar oberhalb der Tabelle:

| Filter | Wo gefiltert | Notiz |
|---|---|---|
| Volltextsuche in description+tags | PW-Selector wo möglich, sonst PHP | Word-Match |
| Template-Filter | PW-Selector | `template=foo\|bar` |
| Image-Feld-Filter | PHP nach `findRaw` | mehrere Felder vom Modul aggregiert |
| „Nur ohne Description" | PW-Selector + PHP-Verifikation | Selector filtert Pages mit ≥1 Match, PHP verifiziert pro Bild |
| „Nur ohne Tags" | dito | dito |
| Tag-Filter | PHP nach `findRaw` | Multi-Select aus tatsächlich vergebenen Tags |

Filter werden URL-state-persisted (`?missing=desc&tpl=foo`), bookmarkbar.

## Sortierung

Spalten-Klick toggelt ascending/descending. Sortierbar:

- Page (alphabetisch)
- Field
- Filename
- Description (leere zuerst / zuletzt toggleable)
- Tags
- Filesize
- Dimensions (Pixel-Fläche)

Default: Page-Title aufsteigend, innerhalb einer Page die native Sort-Position des Felds.

## Pagination

50 Rows/Seite als Default. URL-State `?p=3`. Total-Count + „Seite 3 von 25". Optional Picker (25/50/100/200).

## Permissions

- **Anzeige der Admin-Page:** User braucht `page-edit` auf irgendeiner Page mit Image-Feld (Modul-Check beim Boot)
- **Edit pro Cell:** `$page->editable()` auf der konkreten Ziel-Page (im Save-Endpoint pro Request geprüft)
- Optional separate Permission `media-library-access` für engere Steuerung — wenn vorhanden, scopt das Admin-Page-Sichtbarkeit zusätzlich

## Technische Constraints

- **PHP:** Ausschließlich PW-API (`$pages->findRaw()`, `$pages->getMany()`, `$page->save()`, `$img->size()`, `$cache->save()`, etc.). **Kein direktes SQL.**
- **JS:** Vanilla, Fetch-API, keine Framework-Dependency.
- **CSS:** Verträglich mit AdminThemeUikit (Uikit-Klassen wo möglich).
- **PW-Version:** 3.0.172+ (für `findRaw` mit Subfield-Wildcards) und 3.0.142+ (für Custom-Fields-on-Images)
- **PHP-Version:** 8.0+

## Lizenz

MIT (oder GPL, je nach Repo-Konvention). Modul sollte als Public-Module auf modules.processwire.com einreichbar sein.

## Install / Uninstall

`___install()`:

- Legt Admin-Page „Media Library" unter Setup an, verknüpft mit der `ProcessMediaLibrary`-Process-Klasse
- Optional Permission `media-library-access` anlegen
- Keine Field- oder Template-Änderungen — Modul liest existierende Strukturen

`___uninstall()`:

- Entfernt Admin-Page
- Löscht `media-library-*` Cache-Einträge
- Lässt User-Meta (`mediaLibraryColumns`) stehen (User-Setting, nicht Modul-State)
- Optional Permission stehen lassen (User entscheidet manuell)

## Open Questions

1. **Template-Whitelist**: Auto-discovery aller Templates mit Image-Feldern (Default), oder konfigurierbar via Module-Settings (Whitelist/Blacklist)? Vorschlag: Auto-discovery + optionale Blacklist in Module-Settings.

2. **1-Image-Felder** (`maxFiles=1`): Mit aufnehmen oder per Default ausblenden mit Schalter? Bei 1-Image-Felds (z.B. typische `lead_image`-Felder) ist die Tabelle weniger nützlich als bei Mehrfach-Galerien. Vorschlag: mit-aufnehmen, aber separate Spalten-Gruppierung oder Filter-Default-Aus.

3. **Custom-Field-Spalten Default-Sichtbarkeit**: Auto-sichtbar wenn vorhanden, oder default versteckt und Editor schaltet selbst zu?

4. **Spaltenkonfig-Scope**: Per User in `$user->meta` (Vorschlag) oder global pro Modul-Instanz (Module-Settings)?

5. **Edit-Modus**:

   - (a) Inline-Auto-Save bei Blur (Vorschlag — flüssiges Abarbeiten)
   - (b) „Edit-Modus"-Toggle mit „Save all"-Batch-Button

6. **Filter-URL-State**: Bookmarkbar via URL-Params (Vorschlag) oder Session-only?

7. **Bulk-Operations**: Phase 1 oder Phase 2? Mögliche Operationen: gleichen Tag auf eine Auswahl, Description-Template, Bulk-Delete-Confirm.

8. **Page-Size**: 50 Default OK? Picker?

9. **Vorhandene Module recherchieren**: Vor Build-Start auf modules.processwire.com nach „media library", „file manager", „image manager", „media manager" suchen. Bekannt: Kongondo's „Media Manager" (kommerziell, anderes Datenmodell — Bilder als Pages). Falls etwas Brauchbares existiert: forken oder gemeinsam entscheiden.

10. **Permission-Granularität**: Reicht implizite Vererbung via `$page->editable()` pro Cell (Vorschlag), oder zusätzliche `media-library-access`-Permission als Hard-Gate?

11. **Mobile**: Responsive nötig oder Desktop-only?

12. **Variations-Spalte (Phase 2)**: Pro Bild zeigen welche Varianten existieren (`$img->getVariations()`)? Nützlich für Pre-Warm-Diagnose und Cleanup, aber Phase 2.

13. **Performance-Skalierungs-Ansatz**: Phase 1 mit `findRaw` + WireCache reicht bis ~10k Bilder. Bei Wachstum auf 100k müsste auf `findMany` + per-Image-Index-Cache umgestellt werden. Soll die Architektur Phase 1 bereits diesem Pfad gegenüber offen halten (Cache-Layer abstrahieren?) oder Phase 1 pragmatisch + Phase 2 wenn der Bedarf da ist?

14. **Modul-Info / Versioning**: Welche Versionsstrategie (SemVer)? Wie werden Updates released — via GitHub-Tags + modules.processwire.com? Composer-Support sinnvoll oder PW-typisches manuelles Drop-in?
