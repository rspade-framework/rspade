---
name: document-preview
description: Previewing and searching inside documents in RSX - the <Document_Preview> component and its preview_loaded/page_changed events, the server-side viewer registry and how to override it, the three document.* resolve chains (extract_text, preview_rendition, thumbnail_render), the async text-extraction lifecycle and its EXTRACTED/FAILED/UNSUPPORTED statuses, search_text() with mandatory site scoping, rsx:search:reindex triage, and the LibreOffice/poppler dependencies. Use when showing a PDF or Office document in a page, searching inside uploaded files, plugging in a custom viewer or converter, or debugging missing extracted text.
---

# Document preview and search

Both halves of this system key their work on the **DEDUPLICATED blob** (`File_Storage_Model`), not on the attachment: identical bytes are extracted once and rendered to a cached PDF once, however many attachments point at them. Framework-core — there is nothing to wire up.

---

## Showing a document

```jqhtml
<Document_Preview $attachment_id=this.data.doc.id />
```

Args: `$attachment_id` (**required** — the component throws without it), `$page` (initial page for paginated viewers, default 1), `$width`/`$height` (px; omitted = fill the container).

Public methods, delegating to the active viewer (no-ops before it is ready): `set_page(n)`, `get_page()`, `get_pages()`.

**Events OUT** — listen on the `Document_Preview` itself; it re-emits the active viewer's events:

```javascript
on_ready() {
    this.sid('preview').on('preview_loaded', (c, d) => this.$sid('count').text(d.pages));
    this.sid('preview').on('page_changed',   (c, d) => this.$sid('page').text(d.page));
}
```

`preview_loaded` carries `{pages, width, height, ratio}`; `page_changed` carries `{page}`.

**It is `preview_loaded`, NOT `loaded`.** `loaded` is a reserved jqhtml lifecycle event, fired internally with no payload — a custom event of the same name collides with it. This is the worked instance of the reserved-event rule in the jqhtml docs; if you add your own viewer, name its events the same way.

**Degraded uploads**: when an image's bytes could not be parsed, the attachment carries `preview_unavailable` and the component renders a "Preview unavailable" card instead of instantiating a viewer. Nothing throws.

---

## Viewer resolution (server-side) and overriding it

`Document_Preview` calls `File_Preview_Controller::get_preview_info()` in `on_load()`; the SERVER picks the viewer by mime from `config('rsx.preview.viewers')` — an ordered fnmatch map, first match wins, terminating in `'*' => 'Icon_Viewer'` so every mime resolves to something:

```php
'application/pdf'  => 'Pdf_Viewer',          // + Office mimes (via a PDF rendition)
'image/*'          => 'Image_Viewer',
'*'                => 'Icon_Viewer',
```

Register your own in `rsx/resource/config/rsx.php` under the same key:

```php
'preview' => ['viewers' => [
    'application/vnd.custom.cad' => 'Cad_Viewer',   // simple, manifest-resolved component name
] + config('rsx.preview.viewers')],
```

The three built-ins are rendered by the template; **any other name is instantiated dynamically** into the `$sid="viewer"` host with `{url, extension, file_name, page}`. So a custom viewer is an ordinary jqhtml component that takes those args and — if it wants the page UI to work — implements `set_page`/`get_page`/`get_pages` and fires `preview_loaded`/`page_changed`.

**PDF renditions**: `/_preview/pdf/:key` serves the PDF pdf.js actually loads. A PDF blob is served as-is; a mime listed in `rsx.preview.convertible` becomes a cached `soffice`→PDF rendition under `storage/rsx-renditions/` (LRU-swept to `rsx.preview.quota_max_bytes`); anything else is 415. The route is **dual-gated** (`file.thumbnail.authorize` AND `file.download.authorize`), so your file-access hooks apply to previews exactly as they do to downloads. pdf.js itself is lazy-served from `/_preview/pdfjs.mjs` (+ `pdf_worker.mjs`) out of the committed `node_modules` and is **never bundled**.

---

## The three `document.*` resolve chains

`Rsx::trigger_resolve` chains: **first non-null handler wins; `null` means decline and fall through to the framework default.** (The event mechanism itself is `rspade:event-hooks`.)

| Chain | Overrides | Return contract |
|---|---|---|
| `document.extract_text` | how a blob becomes text | `null` \| `string` (the text) \| `['status'=>'unsupported']` \| `['status'=>'failed','error'=>...]` |
| `document.preview_rendition` | how an attachment becomes a PDF | `null` \| `Response` (takeover) \| `['path'=>abs pdf]` \| `['unsupported'=>true]` (415) |
| `document.thumbnail_render` | how bytes become a raster | `null` \| WebP bytes (string) \| `['unsupported'=>true]` (generic icon) |

```php
#[OnEvent('document.preview_rendition')]
public static function convert_cad($data)
{
    if ($data['attachment']->file_extension !== 'dwg') {
        return null;                                   // decline - framework pipeline continues
    }
    return ['path' => Cad_Service::to_pdf($data['attachment'])];
}
```

Anything outside the contract fails loud. `preview_rendition` runs AFTER the auth gates; `thumbnail_render` runs BEFORE the framework renderer registry.

---

## Text extraction lifecycle

**Extraction is asynchronous — never expect text right after an upload.**

The queue is the `_file_storage.is_indexed` flag: there is **no stored PENDING status**, because the absence of a `_search_indexes` row IS "queued". `Search_Index_Service::index_pending` (`#[Exclusive]`, `*/5` cron plus a prompt kick on blob creation/materialization) drains one blob per iteration. Master switch: `config('rsx.search.enabled')` — off means nothing is kicked and the cron does nothing; existing rows are untouched.

Rows are terminal, one of three:

- **EXTRACTED** — text is stored (capped at `rsx.search.max_text_bytes`, 2MB; over-cap is TRUNCATED and recorded, never silently dropped and never FAILED).
- **FAILED** — an extractor ran and genuinely failed (missing blob, broken binary).
- **UNSUPPORTED** — no configured extractor matched the mime. Images and archives land here, **not** in FAILED. There is no OCR.

That distinction is the point: do not build retry or alerting logic around images. `config('rsx.search.extractors')` is the fnmatch mime map (PDF → pdftotext, Office/RTF → LibreOffice, `text/*`/csv/json → plain).

**A scanned PDF is the trap.** Its mime is `application/pdf`, so it matches pdftotext, runs successfully, and produces **EXTRACTED with EMPTY text** — a page of images has no text layer and there is no OCR to fall back on. It is not FAILED and not UNSUPPORTED. If "the document has no searchable text" matters to your UI, check the content, not just the status:

```php
$status = $attachment->get_extraction_status();          // null = still pending
$text   = $attachment->get_extracted_text();             // '' for an indexed-but-textless blob
if ($status === Search_Index_Model::STATUS_EXTRACTED && $text === '') { /* scanned/textless */ }
```

`get_extraction_status()` reads the column directly and never materializes external bytes; `get_extracted_text()` routes through the blob.

---

## Searching inside documents

```php
File_Attachment_Model::search_text($query, $mode = 'BOOLEAN')   // returns an Eloquent Builder
```

**You MUST add site scoping.** `_search_indexes` has **no `site_id`** — it is keyed on the deduplicated blob and is therefore cross-site by construction. Forgetting this is a tenant-isolation defect, not a cosmetic one:

```php
#[Ajax_Endpoint]
#[Auth('is_logged_in')]
public static function search_documents(Request $request, array $params = [])
{
    return File_Attachment_Model::search_text($params['q'])
        ->where('site_id', Session::get_site_id())          // MANDATORY
        ->where('fileable_type', 'Project_Model')
        ->result_set()
        ->map(fn($a) => ['id' => $a->id, 'name' => $a->file_name]);
}
```

Dedup means one matching blob can back several attachments, so the builder can yield multiple rows per blob — exactly what a Files index wants. `$mode` is `'BOOLEAN'` or `'NATURAL LANGUAGE'` (MySQL FULLTEXT semantics).

---

## Reindex triage

`rsx:search:reindex` takes exactly ONE selector:

| Flag | Use it when |
|---|---|
| `--status` | first: report index counts, re-queue nothing |
| `--attachment=<id>` | one document looks wrong (materializes external bytes) |
| `--storage=<id>` | you already know the blob id |
| `--failed` | a dependency was broken/missing and has been fixed |
| `--below-version=<N>` | an extractor changed; `rsx.search.extractor_version` was bumped |
| `--all` | last resort — the whole corpus |

Start with `--status`. A large `UNSUPPORTED` count is usually correct (images); a large `FAILED` count means a missing binary.

---

## Dependencies

`libreoffice poppler-utils fonts-liberation fonts-dejavu-core` — verified by `php artisan rsx:health` (exit 1 iff a FAIL; WARN/INFO are advisory). No poppler → PDFs go FAILED. No LibreOffice → Office extraction fails and Office previews return 415. Missing fonts → conversions render with substituted glyphs.

LibreOffice concurrency is bounded by a counting semaphore (`rsx.libreoffice.max_concurrent`) shared by thumbnails, extraction and renditions.

**Two owner-sanctioned timeouts live here — do not "fix" them.** `rsx.preview.rendition_timeout` (60s, one soffice→PDF conversion, measured from when it acquires its slot) and `rsx.search.timeout` (30s, one extractor invocation) bound an EXTERNAL process that may never answer, which is the one legitimate case under the framework's no-timeout mandate. They are not caps on our own work and they are not up for removal.

---

## Troubleshooting

- **Nothing renders, no error** — the attachment id is wrong, or the mime resolved to `Icon_Viewer` (no viewer registered for it).
- **Handler never fires on `loaded`** — you listened for the reserved lifecycle event; it is `preview_loaded`.
- **415 from `/_preview/pdf/:key`** — the mime is not in `rsx.preview.convertible`, or LibreOffice is disabled/absent.
- **403 on a preview that a download allows** — the route is dual-gated; check `file.thumbnail.authorize`, not just `file.download.authorize`.
- **`get_extracted_text()` returns null forever** — extraction is off (`rsx.search.enabled`), or the cron is not running; confirm with `rsx:search:reindex --status`.
- **Extracted text is empty for a PDF** — scanned/image-only PDF (see above). No OCR exists.
- **Search returns another tenant's files** — missing `where('site_id', ...)`.

Details: `php artisan rsx:man document_search` · `libreoffice` · `thumbnails`. Related: `rspade:file-attachments`, `rspade:event-hooks`, `rspade:jqhtml`.
