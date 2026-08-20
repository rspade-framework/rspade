# Sample Documents

Redistributable public-domain US-government documents used by the Document Pipeline demo
(`/dev/document_preview`) and imported into the template app by the
`import_sample_documents` migration (`rsx/resource/migrations/`). They exercise the two
document-preview code paths: a native PDF (served directly to pdf.js) and an Office document
(served through the cached LibreOffice PDF rendition).

## sample_report.pdf

- **Title:** "The Recent Large Reduction in Space Launch Cost"
- **Source:** NASA Technical Reports Server (NTRS), citation 20200001093
  https://ntrs.nasa.gov/citations/20200001093
  Direct download:
  https://ntrs.nasa.gov/api/citations/20200001093/downloads/20200001093.pdf
- **Provenance / license:** NASA work. NTRS metadata for this citation reports
  `distribution: PUBLIC`, `determinationType: GOV_PUBLIC_USE_PERMITTED`,
  `containsThirdPartyMaterial: false` -- i.e. cleared for public use / redistribution.
- **Properties:** 10 pages, contains images, ~40 KB of extractable text. Multi-page with real
  prose content -- ideal for exercising pdf.js pagination and text extraction.

## sample_memo.docx

- **Title:** "DHS Section 508 MS Word Test Process"
- **Source:** FEMA / U.S. Department of Homeland Security
  https://training.fema.gov/devres/docs/dhs%20section%20508%20ms%20word%20test%20process.docx
- **Provenance / license:** Work of the U.S. federal government (DHS/FEMA), published for public
  use -- public domain in the United States (17 U.S.C. 105).
- **Properties:** OOXML .docx, ~240 KB, converts cleanly via LibreOffice to a 20-page PDF
  rendition -- exercises the soffice->PDF preview rendition path.

## Notes

Both files were retrieved 2026-07-16. They are committed to the template app (not the framework
core) so downstream installs get working sample data. Replace or extend them freely; the
migration only imports whatever `sample_report.pdf` / `sample_memo.docx` are present and fails
loud if they are missing.
