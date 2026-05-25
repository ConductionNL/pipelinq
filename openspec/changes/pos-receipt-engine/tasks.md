# Tasks: POS Receipt Engine

## 0. Pre-implementation setup

- [ ] 0.1 Verify pos-transaction-core is merged and posTransaction/posTransactionLine schemas are available in pipelinq_register.json
- [ ] 0.2 Verify Pipelinq MailerService exists and can be imported in backend services
- [ ] 0.3 Confirm Twig/Jinja template engine is available in the project (check composer.json for `twig/twig`)
- [ ] 0.4 Verify dompdf or similar PDF library is available for HTML → PDF conversion (check composer.json)
- [ ] 0.5 Check if ESC/POS socket communication requires any system libraries or permissions setup

## 1. Data model: add schemas to pipelinq_register.json

- [ ] 1.1 Add receiptTemplate schema with properties:
  - name (string, required)
  - description (string)
  - body (string, required, Twig syntax)
  - layoutWidth (integer, default 42)
  - companyLogoUrl (string)
  - isInvoiceMode (boolean, default false)
  - status (enum: draft/active/archived, default draft)
  - organizationId (string for multi-tenant)

- [ ] 1.2 Add receiptPrintLog schema with properties:
  - transaction (string, required, UUID reference to posTransaction)
  - template (string, required, UUID reference to receiptTemplate)
  - action (enum: print/email, required)
  - printerDevice (string)
  - emailRecipient (string)
  - renderedContent (string, optional)
  - status (enum: success/failed/pending, required)
  - errorMessage (string)
  - printedAt (string, ISO timestamp)

- [ ] 1.3 Create database migration for receiptTemplate table
- [ ] 1.4 Create database migration for receiptPrintLog table
- [ ] 1.5 Verify schemas are indexed on transaction, template, action, and status for query performance

## 2. Backend: Receipt printer service

- [ ] 2.1 Create `src/Services/PosReceiptPrinter.php` with:
  - `print(posTransaction, receiptTemplate, string $printerAddr): PrintResult` method
  - `getDeviceStatus(string $printerAddr): DeviceStatus` method
  - `generateEscPosCommands(string $renderedContent, receiptTemplate): string` method

- [ ] 2.2 Implement socket connection logic:
  - Connect to printer IP:port with 5-second timeout
  - Send ESC/@ (reset) command
  - Send receipt data as bytes
  - Send GS/V (full cut) at end
  - Wait for status response from printer
  - Close socket gracefully

- [ ] 2.3 Implement ESC/POS command generation:
  - Handle bold text (ESC/E on/off)
  - Handle font size selection (ESC/!)
  - Handle text alignment (ESC/a)
  - Handle barcode encoding (GS/k for Code128 or Code39)
  - Handle line wrapping per layoutWidth property
  - Handle character encoding for Dutch special characters (€, ©, ®)

- [ ] 2.4 Implement error handling:
  - Timeout on socket connection (log and return error)
  - Invalid printer address (validate IP:port format)
  - Offline status from printer (return human-readable error)
  - Socket write failures (retry once, then fail)

## 3. Backend: Receipt mailer service

- [ ] 3.1 Create `src/Services/PosReceiptMailer.php` with:
  - `sendReceipt(posTransaction, receiptTemplate, string $emailAddr): MailResult` method
  - `renderReceipt(posTransaction, receiptTemplate): string` method
  - `generatePdf(string $renderedReceipt): PdfBinary` method

- [ ] 3.2 Implement Twig template rendering:
  - Create Twig environment with transaction, company, and system variables in context
  - Render template body with posTransaction object
  - Catch Twig exceptions and return meaningful error messages
  - Support nested Twig variables (transaction.lines, transaction.totals, etc.)

- [ ] 3.3 Implement email formatting:
  - Render to plain text (per template config)
  - Render to HTML with embedded CSS (per template config)
  - Generate PDF from HTML using dompdf (if configured)
  - Create MailMessage object with proper headers

- [ ] 3.4 Integrate with Pipelinq MailerService:
  - Call MailerService.sendMail() with rendered receipt
  - Handle mail queue responses (accepted, queued, failed)
  - Log mail send events to receiptPrintLog

## 4. Backend: Receipt controller and API endpoints

- [ ] 4.1 Create `src/Controllers/Api/PosReceiptController.php` with REST endpoints:
  - POST /api/pos/transactions/{id}/print
  - POST /api/pos/transactions/{id}/email
  - GET /api/pos/templates
  - POST /api/pos/templates
  - PUT /api/pos/templates/{id}
  - DELETE /api/pos/templates/{id}
  - GET /api/pos/templates/{id}/preview
  - GET /api/pos/receipt-logs
  - GET /api/pos/printers/status

- [ ] 4.2 Implement POST /api/pos/transactions/{id}/print:
  - Extract transaction and template from request
  - Validate transaction exists and has settled/confirmed status
  - Call PosReceiptPrinter.print()
  - Create receiptPrintLog entry
  - Return PrintResult with status code and message

- [ ] 4.3 Implement POST /api/pos/transactions/{id}/email:
  - Extract transaction, template, and emailAddr from request
  - Validate email format
  - Call PosReceiptMailer.sendReceipt()
  - Create receiptPrintLog entry with status=success/failed
  - Return MailResult status

- [ ] 4.4 Implement GET /api/pos/templates:
  - Return all active templates for current organization
  - Include name, description, layoutWidth, isInvoiceMode, status
  - Filter by status (active, draft, archived) per query param
  - Sort by createdAt DESC

- [ ] 4.5 Implement POST /api/pos/templates (create template):
  - Accept receiptTemplate object in request body
  - Validate name is not empty
  - Validate Twig syntax in body before saving
  - Set status = draft by default
  - Set organizationId to current user's organization
  - Return created template with new UUID

- [ ] 4.6 Implement PUT /api/pos/templates/{id} (update template):
  - Accept partial receiptTemplate updates
  - Re-validate Twig syntax if body is updated
  - Prevent updates to archived templates (return 410 Gone)
  - Return updated template

- [ ] 4.7 Implement DELETE /api/pos/templates/{id} (archive template):
  - Set status = archived (soft delete)
  - Return 204 No Content
  - Prevent deletion of templates referenced in recent receiptPrintLog entries (check last 7 days)

- [ ] 4.8 Implement GET /api/pos/templates/{id}/preview:
  - Retrieve template by id
  - Fetch sample transaction (first from database or use test fixture)
  - Render template with sample transaction
  - Return rendered receipt as plain text in response body

- [ ] 4.9 Implement GET /api/pos/receipt-logs:
  - Return paginated receiptPrintLog entries
  - Filter by transaction, template, action, status per query params
  - Include date range filter (createdAt between dates)
  - Sort by createdAt DESC (most recent first)
  - Include renderedContent (optional) in response

- [ ] 4.10 Implement GET /api/pos/printers/status:
  - Call PosReceiptPrinter.getDeviceStatus() for configured printer
  - Return device status, vendor, model, paper status, etc.
  - Return error message if connection fails

## 5. Frontend: Receipt template management UI

- [ ] 5.1 Create `src/Views/ReceiptTemplateList.vue`:
  - Display table of templates: name, status, layoutWidth, organization
  - Filter by status (active, draft, archived)
  - Sorting by createdAt
  - Create, Edit, Duplicate, Archive buttons

- [ ] 5.2 Create `src/Views/ReceiptTemplateDetail.vue`:
  - Display form with fields: name, description, layoutWidth, isInvoiceMode
  - WYSIWYG editor for Twig body with syntax highlighting
  - Live preview pane showing rendered receipt with sample transaction
  - Test/Preview button to render with current sample
  - Save, Cancel, Delete buttons
  - Show Twig syntax error messages in editor

- [ ] 5.3 Create `src/Components/ReceiptPreviewPane.vue`:
  - Display rendered receipt in monospace font
  - Show character count and line width validation
  - Highlight any rendering errors from Twig syntax
  - Update in real-time as template body is edited

- [ ] 5.4 Create `src/Components/ReceiptTemplateForm.vue`:
  - Reusable form component for create/edit
  - Validate name and Twig syntax before enabling Save
  - Show validation errors inline

## 6. Frontend: Transaction detail print/email modals

- [ ] 6.1 Create `src/Components/PrintReceiptModal.vue`:
  - Modal with template dropdown (pre-filled with default)
  - Printer IP:port selector (dropdown of configured printer + custom entry)
  - Preview pane showing rendered receipt
  - Print and Cancel buttons
  - Show status messages (success, error, device offline)

- [ ] 6.2 Create `src/Components/EmailReceiptModal.vue`:
  - Modal with template dropdown
  - Email recipient input (with validation)
  - Preview pane
  - Send and Cancel buttons
  - Show status messages

- [ ] 6.3 Create `src/Components/PrinterStatusPanel.vue`:
  - Display currently configured printer IP:port
  - Status indicator (online/offline/error)
  - Device info (vendor, model) if available
  - Test button to check status
  - Show last check timestamp

- [ ] 6.4 Integrate Print/Email buttons into `src/Views/TransactionDetail.vue`:
  - Add "Print Receipt" button in actions section
  - Add "Email Receipt" button in actions section
  - Wire buttons to open respective modals
  - Pass transaction UUID to modals
  - Handle modal close and success/error states

## 7. Frontend: Admin settings

- [ ] 7.1 Create `src/Views/Settings/ReceiptSettings.vue`:
  - Printer configuration: IP address input, port input
  - Email sender input (validated email format)
  - Default template dropdown (read from GET /api/pos/templates)
  - Test Printer button (calls GET /api/pos/printers/status)
  - Save button
  - Show validation errors

- [ ] 7.2 Create `src/Views/Settings/ReceiptLogViewer.vue`:
  - Display paginated table of receiptPrintLog entries
  - Columns: transaction, template, action, status, date, device/recipient
  - Filter by date range, status, action, template
  - Reprint button for each log entry (calls POST /api/pos/transactions/{id}/print with template={template})
  - Show renderedContent in detail view (expand/collapse)

## 8. Internationalization (i18n)

- [ ] 8.1 Add English translation keys to `l10n/en.json`:
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

- [ ] 8.2 Add Dutch translation keys to `l10n/nl.json`:
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

- [ ] 8.3 Verify all keys are present in both en.json and nl.json (no gaps per ADR-007)

## 9. Testing: Unit tests

- [ ] 9.1 Test PosReceiptPrinter.generateEscPosCommands():
  - Verify ESC/POS header and footer (ESC/@, GS/V)
  - Verify bold encoding (ESC/E)
  - Verify text wrapping to layoutWidth
  - Verify special characters (€, ©) are encoded correctly

- [ ] 9.2 Test PosReceiptMailer.renderReceipt():
  - Verify Twig variables are accessible (transaction, company, etc.)
  - Verify date formatting per locale
  - Verify error handling for invalid Twig syntax
  - Verify line item loop rendering

- [ ] 9.3 Test PosReceiptController endpoints:
  - POST /api/pos/transactions/{id}/print returns 200/400/500 appropriately
  - GET /api/pos/templates returns list with filters
  - PUT /api/pos/templates/{id} validates Twig syntax
  - GET /api/pos/templates/{id}/preview returns rendered receipt

## 10. Testing: Integration tests

- [ ] 10.1 Test end-to-end: Create template → Print receipt → Check receiptPrintLog entry
  - Verify receiptPrintLog has correct template, transaction, action, status
  - Verify renderedContent is captured (if full content logging enabled)

- [ ] 10.2 Test end-to-end: Email receipt workflow
  - Mock Pipelinq MailerService
  - Call POST /api/pos/transactions/{id}/email
  - Verify MailerService.sendMail() called with correct recipient
  - Verify receiptPrintLog entry created with action=email

- [ ] 10.3 Test template validation:
  - Attempt to save template with invalid Twig syntax
  - Verify API returns 400 with error message
  - Verify template is not saved

- [ ] 10.4 Test printer status polling:
  - Mock ESC/POS socket connection
  - Call GET /api/pos/printers/status
  - Verify status byte is read correctly
  - Verify timeout handling (connection fails after 5s)

## 11. Testing: Manual (browser)

- [ ] 11.1 Create receipt template: Admin Settings → Receipts → Create → name "Test Template" → Twig body → Save
- [ ] 11.2 Verify template is saved and appears in Settings → Receipts → Templates list
- [ ] 11.3 Open transaction detail → Click "Print Receipt" → Select template and printer → Verify preview shows receipt
- [ ] 11.4 Click "Print" button → If printer is mocked, verify receiptPrintLog entry is created
- [ ] 11.5 Open transaction detail → Click "Email Receipt" → Enter valid email → Click "Send" → Verify success message
- [ ] 11.6 Navigate to Settings → Receipt Logs → Verify print and email entries appear with correct details
- [ ] 11.7 Test with transaction >= EUR 100: Verify isInvoiceMode=true template renders BTW breakdown
- [ ] 11.8 Test with transaction < EUR 100: Verify standard template renders without BTW
- [ ] 11.9 Test printer offline scenario: Admin disconnects printer from network → Try to print → Verify error message
- [ ] 11.10 Test email with invalid address: Try to email to "not-an-email" → Verify validation error shows

## 12. Documentation

- [ ] 12.1 Update CLAUDE.md with:
  - Overview of receipt template system
  - Twig template variable reference (transaction, company, etc.)
  - ESC/POS limitations and character set support
  - Printer configuration troubleshooting

- [ ] 12.2 Create user guide (public docs):
  - How to create and customize receipt templates
  - How to print/email receipts
  - Troubleshooting printer connectivity

## 13. Verification

- [ ] 13.1 Verify receiptTemplate and receiptPrintLog schemas are indexed on transaction_id, template_id, status
- [ ] 13.2 Verify all translation keys are used via t() function, not hardcoded strings
- [ ] 13.3 Verify PosReceiptPrinter handles connection timeout gracefully (no hanging)
- [ ] 13.4 Verify PosReceiptMailer does not expose internal errors in user-facing messages
- [ ] 13.5 Verify receiptPrintLog entries are immutable (no updates after creation, only reads)
- [ ] 13.6 Run full test suite: unit + integration + manual tests all pass
