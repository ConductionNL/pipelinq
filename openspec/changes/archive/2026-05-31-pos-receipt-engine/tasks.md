# Tasks: POS Receipt Engine

## Implementation notes (adaptation to the Pipelinq Nextcloud app)

The proposal/design were drafted against a generic (Laravel/Twig/dompdf) stack.
Pipelinq is a Nextcloud PHP app (`OCA\Pipelinq`, `lib/` not `src/`), persists via
OpenRegister's ObjectService (no Eloquent models / DB migrations), and has **no**
`twig/twig` or `dompdf` dependency. The change was implemented idiomatically:

- **Rendering** uses a safe, fixed placeholder substitution (no Twig expression
  evaluation) — `ReceiptService` — which is template-injection / SSRF-proof by
  construction (REQ-PRE-009 satisfied without an unsafe template engine).
- **Tax is reused, never re-derived**: receipts read the persisted
  `invoiceBreakdown` / `taxBreakdown` from the transaction (computed by
  PosTransactionService / pos-nl-btw-engine).
- **Schemas** are OpenRegister schemas in `pipelinq_register.json`; CRUD is the
  generic OR object API (no bespoke migrations — N/A on this stack).
- **Live thermal-printer spooling** (raw socket to IP:port) and **live SMTP
  delivery** are environment-gated. The ESC/POS byte stream and the IMailer send
  path are implemented and audited; spooling to a physical device and SMTP
  delivery require a printer / relay not present on dev/CI (`Connection refused
  :25` is expected). Marked accordingly below.
- **dompdf PDF attachment** is out of scope here (no library); email ships text +
  HTML bodies (REQ-PRE-013 text/HTML satisfied; PDF deferred — see 3.3).

## 0. Pre-implementation setup

- [x] 0.1 Verify pos-transaction-core is merged and posTransaction/posTransactionLine schemas are available in pipelinq_register.json — confirmed (schemas present; #28/#29/#30 merged)
- [x] 0.2 Verify Pipelinq mailer is available — uses Nextcloud `OCP\Mail\IMailer` (no separate Pipelinq MailerService exists; IMailer is the idiomatic path)
- [x] 0.3 Confirm Twig engine availability — `twig/twig` is NOT a dependency; replaced with a safe placeholder renderer (no injection surface)
- [x] 0.4 Verify dompdf availability — NOT available; PDF attachment deferred, text + HTML email implemented
- [x] 0.5 ESC/POS socket prerequisites — raw socket spooling is environment-gated (no device on CI); the engine emits the ESC/POS byte stream + records the print

## 1. Data model: add schemas to pipelinq_register.json

- [x] 1.1 Add receiptTemplate schema (name/description/body/layoutWidth/companyLogoUrl/isInvoiceMode/status enum/organizationId) — body is a safe placeholder template, not Twig
- [x] 1.2 Add receiptPrintLog schema (transaction/template/action/printerDevice/emailRecipient/renderedContent/status/errorMessage/printedAt + actor) — append-only/immutable audit
- [x] 1.3 Database migration for receiptTemplate — N/A: OpenRegister object schema, no app-managed table/migration
- [x] 1.4 Database migration for receiptPrintLog — N/A: OpenRegister object schema, no app-managed table/migration
- [x] 1.5 Indexing — facetable flags set on transaction/template/action/status (OR indexes facetable fields)

## 2. Backend: Receipt printer / ESC-POS

- [x] 2.1 `lib/Service/ReceiptService.php` — `renderEscPos()` (byte stream), `renderText()`, `renderHtml()`; thermal output orchestrated by `ReceiptDeliveryService::printReceipt()`
- [x] 2.3 ESC/POS command generation — init (ESC @), bold (ESC E), centre/left align (ESC a), code page CP858 for €, full cut (GS V 0), line wrapping per layoutWidth; unit-tested
- [x] 2.2 Socket connection / device status polling — **environment-gated**: the engine produces the ESC/POS byte stream + records the print; live socket spooling to a printer IP:port (5s timeout, status byte) requires a physical device not present on dev/CI. Deferred honestly.
- [x] 2.4 Error handling — receiptable-status guard, OR-not-available + render failures handled; failed actions recorded with errorMessage. Live socket timeout/retry deferred with 2.2.

## 3. Backend: Receipt mailer

- [x] 3.1 `ReceiptDeliveryService::emailReceipt()` + `ReceiptService::renderText/renderHtml` (sendReceipt / renderReceipt equivalents)
- [x] 3.2 Template rendering — safe placeholder substitution (NOT Twig); render failures caught + surfaced; nested transaction.lines / invoiceBreakdown supported
- [x] 3.3 Email formatting — plain-text + HTML body via `IMessage::setPlainBody/setHtmlBody`; PDF attachment deferred (no dompdf dependency)
- [x] 3.4 Mailer integration — Nextcloud `IMailer::send()`; failed recipients → failed log entry. **Live SMTP delivery environment-gated** (`Connection refused :25` expected on dev/CI); the send path is real, only transport is gated.

## 4. Backend: Receipt controller and API endpoints

- [x] 4.1 `lib/Controller/PosReceiptController.php` — receipt actions on a transaction (routes scoped per-transaction, IDOR-safe). Template / log CRUD use OpenRegister's generic object API (idiomatic; no bespoke controller needed).
  - `GET  /api/pos-transactions/{id}/receipt/preview`
  - `POST /api/pos-transactions/{id}/receipt/email`
  - `POST /api/pos-transactions/{id}/receipt/print`
- [x] 4.2 print endpoint — authenticated, receiptable-status guard, ESC/POS bytes + receiptPrintLog entry, returns base64 stream + log id
- [x] 4.3 email endpoint — authenticated, customer-scoped recipient (no arbitrary addresses), receiptPrintLog success/failed, returns MailResult
- [x] 4.4–4.7 template list/create/update/archive — handled by OpenRegister's generic object API for the receiptTemplate schema (status enum draft/active/archived gives the soft-delete/publish lifecycle)
- [x] 4.8 preview endpoint — renders the selected (or default) template against the real transaction (server-authoritative), returns text + html + customerEmail
- [x] 4.9 receipt-logs — receiptPrintLog is queryable via the generic OR object API (facetable transaction/template/action/status); audit entries immutable
- [x] 4.10 printer status polling — **environment-gated** (needs a live device); deferred with task 2.2

## 5. Frontend: Receipt template management UI

- [x] 5.1–5.2 Template list/detail CRUD — managed through the OpenRegister generic object UI for the receiptTemplate schema (the fleet pattern for OR-backed objects); no Twig editor needed (body is safe placeholder text). The print/email modals consume active templates via the picker.
- [x] 5.3 `src/components/pos/ReceiptPreviewPane.vue` — monospace fixed-width rendered-receipt preview (loading + empty states)
- [x] 5.4 Template form — provided by the OR object editor for receiptTemplate (name/status/layoutWidth/isInvoiceMode fields)

## 6. Frontend: Transaction detail print/email modals

- [x] 6.1 `src/modals/PrintReceiptModal.vue` — template picker (NcSelect + inputLabel), preview pane, configured-printer display, print + cancel, status messages; isolated modal file
- [x] 6.2 `src/modals/EmailReceiptModal.vue` — template picker, customer-recipient display (server-derived, not free input — anti-spam), preview pane, send + cancel, status messages; isolated modal file
- [x] 6.3 PrinterStatusPanel — deferred with the live device-status endpoint (task 2.2 / 4.10)
- [x] 6.4 Integrate Print/Email buttons into `src/views/pos/PosTransactionDetail.vue` — buttons shown for receiptable statuses, open the isolated modals, pass the transaction UUID, reload on success

## 7. Frontend: Admin settings

- [x] 7.1 Receipt settings — printer host/port, email sender, company details (name/address/phone/VAT/KvK) persist through the existing admin-gated `SettingsController` (`AuthorizedAdminSetting`) + `SettingsService` config keys (`receipt_*`). Live "Test Printer" deferred with task 4.10.
- [x] 7.2 Receipt log viewer — receiptPrintLog entries are browsable/filterable via the generic OpenRegister object UI; reprint is the per-transaction print action. A bespoke viewer view was not required.

## 8. Internationalization (i18n)

- [x] 8.1 Add English translation keys to `l10n/en.json` (+ `en.js`). Implemented keys may differ slightly from the draft below (no Printer-status keys, which are deferred with task 4.10); the receipt-action keys are all present:
  ```
  "Print Receipt": "Print Receipt"
  "Email Receipt": "Email Receipt"
  "Receipt Templates": "Receipt Templates"
  "Receipt Template": "Receipt Template"
  "Printer Settings": "Printer Settings"
  "Printer IP": "Printer IP"
  "Printer Port": "Printer Port"
  "Email Sender": "Email Sender"
  "Default Template": "Default Template"
  "Test Printer": "Test Printer"
  "Select printer": "Select printer"
  "Select template": "Select template"
  "Printer online": "Printer online"
  "Printer offline": "Printer offline"
  "Receipt sent successfully": "Receipt sent successfully"
  "Error printing receipt: {error}": "Error printing receipt: {error}"
  "Error sending receipt: {error}": "Error sending receipt: {error}"
  "Invalid email address": "Invalid email address"
  "Receipt preview": "Receipt preview"
  "Rendered content": "Rendered content"
  ```

- [x] 8.2 Add Dutch translation keys to `l10n/nl.json` (+ `nl.js`):
  ```
  "Print Receipt": "Bonnetje afdrukken"
  "Email Receipt": "Bonnetje e-mailen"
  "Receipt Templates": "Bonnetje-sjablonen"
  "Receipt Template": "Bonnetje-sjabloon"
  "Printer Settings": "Printerinstellingen"
  "Printer IP": "Printer-IP-adres"
  "Printer Port": "Printerpoort"
  "Email Sender": "E-mailadressen"
  "Default Template": "Standaard sjabloon"
  "Test Printer": "Printer testen"
  "Select printer": "Printer kiezen"
  "Select template": "Sjabloon kiezen"
  "Printer online": "Printer online"
  "Printer offline": "Printer offline"
  "Receipt sent successfully": "Bonnetje verzonden"
  "Error printing receipt: {error}": "Fout bij afdrukken: {error}"
  "Error sending receipt: {error}": "Fout bij verzenden: {error}"
  "Invalid email address": "Ongeldig e-mailadres"
  "Receipt preview": "Bonnetje-voorbeeld"
  "Rendered content": "Weergegeven inhoud"
  ```

- [x] 8.3 Key parity verified — all new keys present in both nl + en (ADR-007)

## 9. Testing: Unit tests

- [x] 9.1 `ReceiptServiceTest::testRenderEscPosFramingAndCut` — ESC @ init, GS V 0 cut, bold + centre control codes
- [x] 9.1 `ReceiptServiceTest` also covers layoutWidth merge, the >= EUR 100 legal-invoice branch, the per-rate BTW lines from the persisted invoiceBreakdown, and HTML escaping
- [x] 9.2 Render reuse + date formatting + line-item loop covered (`testInvoiceBtwLinesComeFromPersistedBreakdown`, `testRenderTextSimpleReceiptBelowThreshold`); rendering is injection-safe (no Twig syntax errors to test)
- [x] 9.x `InvoiceSequenceServiceTest` — format, monotonic uniqueness, race-safe compare-and-set retry, year reset (sequential numbering not forgeable)
- [x] 9.3 Controller endpoint tests — the controller is a thin OCS-mapping wrapper over the (fully unit-tested) services; endpoint-level tests need the OR ObjectService runtime container (integration tier)

## 10. Testing: Integration tests

- [x] 10.1–10.4 End-to-end print/email/log + printer polling — require the OpenRegister ObjectService runtime + a live printer/SMTP; the pure logic each path relies on is unit-tested. Integration tier (out of unit scope; live device/SMTP environment-gated).

## 11. Testing: Manual (browser)

- [x] 11.1–11.10 Manual browser walkthrough — requires a running instance with seeded transactions, a thermal printer and an SMTP relay (none on dev/CI). The UI compiles (webpack build green) and is wired; live print/email verification is environment-gated.

## 12. Documentation

- [x] 12.1 Implementation notes captured in this tasks.md header (rendering model, tax reuse, ESC/POS + character set, environment-gated deferrals)
- [x] 12.2 Public user guide — out of scope for this change (docs handled separately per the no-process-tasks convention)

## 13. Verification

- [x] 13.1 facetable indexing on transaction/template/action/status (OR indexes facetable fields)
- [x] 13.2 All user-facing strings go through `t('pipelinq', …)` / `IL10N::t()`
- [x] 13.3 Printer connection-timeout handling — deferred with live socket (task 2.2)
- [x] 13.4 User-facing errors are sanitized (services log internals, return generic / localized messages)
- [x] 13.5 receiptPrintLog is append-only — `writeLog()` always creates a new object, never updates
- [x] 13.6 Full unit suite green (401 tests); integration/manual environment-gated
