# Core/Files — pointer

This directory's documentation now lives in the man pages and the skill tier; this file
is a pointer, not a second copy.

- **Uploading / claiming / the upload gate / size ceiling**: `rsx:man file_upload`
- **Deletion, retention, blob release, disposal hooks**: `rsx:man file_disposal`
- **Thumbnails + the renderer registry**: `rsx:man thumbnails`
- **Text extraction, full-text search, `<Document_Preview>`, PDF renditions**: `rsx:man document_search`
- **Headless soffice slot pool**: `rsx:man libreoffice`
- Skills: `rspade:file-attachments` (working with attachments), `rspade:document-preview`

Historical note with no man home (WP-A external byte residency), preserved verbatim:

> Rolled-in mime fix: serving reads `$attachment->mime_type` (the old `$storage->mime_type` never existed). `file_size` is authoritative and works without a resident blob (`get_size()` reads the column).
