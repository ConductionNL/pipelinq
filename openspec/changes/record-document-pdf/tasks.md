# Tasks: record-document-pdf

### Task 1: The document endpoints
- **spec_ref**: `openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-a-configured-record-type-has-a-document`
- **files**: `lib/Controller/RecordDocumentController.php`, `lib/Service/CallerObjectReader.php`, `appinfo/routes.php`
- [x] Implement: `record_document_templates`, `GET /api/records/{schema}/document-template`, `GET /api/records/{schema}/{id}/document`
- [x] Test: `tests/Unit/Controller/RecordDocumentControllerTest.php`

### Task 2: Access before the render
- **spec_ref**: `openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-the-caller-may-act-on-the-record-before-anything-is-made`
- **files**: `lib/Controller/RecordDocumentController.php`
- [x] Implement: owner or privileged, checked before filinq is called
- [x] Test: `tests/Unit/Controller/RecordDocumentControllerTest.php`

### Task 3: The quote's "Voorbeeld pdf"
- **spec_ref**: `openspec/changes/record-document-pdf/specs/record-documents/spec.md#requirement-the-document-is-offered-only-when-it-can-be-made`
- **files**: `src/manifest.d/82-quotes.json`, `l10n/en.json`, `l10n/nl.json`
- [x] Implement: header action, visible on `available`
- [x] Test: `tests/vitest/quotesBoardLook.spec.js`
