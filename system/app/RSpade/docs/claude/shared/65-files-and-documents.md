<!-- single-source: never duplicate into another fragment. THIS FRAGMENT IS THE CANONICAL HOME of the upload gate, the size ceiling and the delete()-is-retention rule. -->

## FILE ATTACHMENTS

Two models, one dedup boundary: `File_Storage_Model` = the physical content-addressed blob, `File_Attachment_Model` = the logical upload with its metadata and owner. Identical bytes are stored — and text-extracted, and previewed — exactly once. The flow is always upload-unattached -> validate -> claim onto a record (`find_by_key()` + `can_user_assign_this_file()` + `attach_to()`/`add_to()`); the client transport is `Ajax.upload(form_data)`.

**The upload gate is MANDATORY.** `POST /_upload` THROWS when no `file.upload.authorize` handler is registered — an unhandled gate would be an anonymous upload endpoint. Every app ships one handler in `/rsx/handlers/` (minimum: require login).

**Never hardcode the size ceiling in a label** — "Max size 25MB" is wrong the day the limit changes and nothing breaks to tell you. One number both languages read (`config('rsx.files.max_file_size')` / `window.rsxapp.files.max_file_size`), rendered by `Ajax::max_file_size_human()` / `Ajax.max_file_size_human()`. **`0` means no framework ceiling — read it as UNLIMITED, never as "reject everything".**

**`delete()` ENTERS a recoverable retention window; it does not destroy anything.** The blob is preserved and the attachment is recoverable (`get_deleted_attachments()` / `undelete()`). `force_destroy()` is the only immediate erasure, and `File_Disposal_Service` is the SOLE blob-release authority.

## DOCUMENT SEARCH & PREVIEW

Framework-core, no app wiring, both halves keyed on the **deduplicated blob**. Uploads are auto-queued for text extraction and previewable with `<Document_Preview $attachment_id=... />` (PDF via pdf.js, Office via a cached rendition, images and icons otherwise).

**Text extraction is ASYNC — never expect text right after upload** (`get_extraction_status()` is null while pending; statuses are EXTRACTED / FAILED / UNSUPPORTED, no OCR). **`File_Attachment_Model::search_text($query)` returns a Builder the app MUST site-scope** — `_search_indexes` has no `site_id` and is cross-site by construction, the one place the framework does not scope for you.

Skills: `rspade:file-attachments` (claim flow, URLs, thumbnails, disposal hooks, ZIP downloads), `rspade:document-preview` (viewer registry, the three `document.*` resolve chains, reindex triage, deps). Details: `rsx:man file_upload`, `rsx:man document_search`.
