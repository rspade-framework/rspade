# Reference documents

Two real, redistributable public-domain US-government documents, seeded by
`Sample_Data_Service` beside the generated sample documents (attached to Megadodo
Publications), and read by path by the framework's document tests. Unlike the generated files
they are long and real: a ten-page PDF for pdf.js pagination and text extraction, and a Word
document whose LibreOffice rendition runs to twenty pages.

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
core). The seeder fails loud if either is missing, so remove them from sample_data.json's
`reference_documents` before deleting them.
