# Design: POS Receipt Engine

## Architecture

### Data Layer

Three new OpenRegister schemas introduced:

#### receiptTemplate
Stores customizable receipt template configurations with Twig syntax support.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| name | string | Yes | Template name (e.g., "Standard Receipt", "Invoice Receipt") |
| description | string | No | Template description |
| body | string | Yes | Twig template source (uses transaction and company data) |
| layoutWidth | integer | No | Thermal printer line width in characters (default: 42) |
| companyLogoUrl | string | No | URL to company logo image for receipt header |
| isInvoiceMode | boolean | No | When true, renders full BTW breakdown and compliance footer |
| status | string | No | Draft / Active / Archived (Active = used in production) |
| organizationId | string | No | UUID of organization (for multi-tenant isolation) |

#### receiptPrintLog
Audit log of all printed and emailed receipts for compliance.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| transaction | string | Yes | UUID reference to posTransaction |
| template | string | Yes | UUID reference to receiptTemplate used |
| action | string | Yes | print or email |
| printerDevice | string | No | IP:port or device name (for print actions) |
| emailRecipient | string | No | Email address (for email actions) |
| renderedContent | string | No | Actual rendered receipt content (for audit) |
| status | string | Yes | success / failed / pending |
| errorMessage | string | No | Error details if status = failed |
| printedAt | string | No | ISO timestamp of successful print |

### Frontend

#### receiptTemplate Management (Admin Settings)
New admin section: Settings → Receipts → Templates

- List view: name, status, layout width, organization
- Detail/edit: WYSIWYG editor for Twig template with live preview
- Preview panel: shows rendered sample receipt using test transaction
- Duplicate template button

#### Transaction Detail Actions
On the POS transaction detail view, two new action buttons below the transaction summary:

- **Print Receipt** button → opens modal with printer device selector and template picker
- **Email Receipt** button → opens modal with recipient email and template picker

Both modals include a preview pane showing the rendered receipt.

#### Printer Status Panel
Display below action buttons:
- Currently configured printer IP/port
- Status indicator (online / offline / error)
- Device info (vendor, model) if available via ESC/POS identification commands

### Backend

#### PosReceiptPrinter Service

Handles ESC/POS protocol generation and device communication.

```php
class PosReceiptPrinter {
  public function print(posTransaction $trans, receiptTemplate $tpl, string $printerAddr): PrintResult
  public function getDeviceStatus(string $printerAddr): DeviceStatus
  public function generateEscPosCommands(string $renderedContent, receiptTemplate $tpl): string
}
```

Key methods:
- `generateEscPosCommands()` — converts rendered text to ESC/POS bytes (font selection, bold, underline, cut, barcode)
- `print()` — opens socket to printer IP:port, streams ESC/POS data, waits for status response
- Device detection via ESC/POS status response byte

#### PosReceiptMailer Service

Integrates with Pipelinq's `MailerService` to render and send receipts.

```php
class PosReceiptMailer {
  public function sendReceipt(posTransaction $trans, receiptTemplate $tpl, string $emailAddr): MailResult
  public function renderReceipt(posTransaction $trans, receiptTemplate $tpl): string
  public function generatePdf(string $renderedReceipt): PdfBinary
}
```

Key methods:
- `renderReceipt()` — Twig `render()` with transaction object and system config
- `generatePdf()` — uses `dompdf` library to convert HTML receipt to PDF (optional)
- `sendReceipt()` — submits to Pipelinq Mail queue with Mailer service

#### PosReceiptController

REST endpoints for receipt operations:

```
POST   /api/pos/transactions/{id}/print           (print to configured printer)
POST   /api/pos/transactions/{id}/email           (send to email)
GET    /api/pos/templates                         (list templates)
POST   /api/pos/templates                         (create template)
PUT    /api/pos/templates/{id}                    (update template)
DELETE /api/pos/templates/{id}                    (archive template)
GET    /api/pos/templates/{id}/preview            (preview with sample data)
GET    /api/pos/receipt-logs                      (audit log query)
GET    /api/pos/printers/status                   (get configured printer status)
```

### Integration Points

#### Pipelinq Mailer
Uses existing `MailerService` to queue email jobs. Receipt content submitted as either:
- Plain text (simple receipts)
- HTML table (styled receipts)
- PDF attachment (formal invoices)

#### Admin Settings
New settings keys:
- `pos.printer.host` — IP address of thermal printer
- `pos.printer.port` — ESC/POS port (default: 9100)
- `pos.email.defaultTemplate` — UUID of default receipt template
- `pos.email.sender` — Sender email address for receipt emails

#### Pipelinq Transactions
When "Print Receipt" or "Email Receipt" action completes, the `receiptPrintLog` is created and the transaction's `receipts` array is updated with a reference to the new log entry.

## Components

### receiptTemplate Schema

```json
{
  "name": "Standaard Bonnetje",
  "description": "Standard POS receipt for all transactions",
  "body": "{% autoescape false %}\n{{ company.name }}\n{{ company.address }}\nTel: {{ company.phone }}\n\n====================================\nBONNETJE\n{{ transaction.createdAt|date('d-m-Y H:i') }}\n\n{% for line in transaction.lines %}\n{{ line.description|truncate(30) }} x{{ line.quantity }}\n  €{{ (line.unitPrice * line.quantity)|number_format(2) }}\n{% endfor %}\n\nSubtotaal: €{{ transaction.subtotal|number_format(2) }}\nKorting:   €{{ transaction.discount|number_format(2) }}\n{{ transaction.total|number_format(2) }}\n\nBedankt!\n====================================\n{% endautoescape %}",
  "layoutWidth": 42,
  "isInvoiceMode": false,
  "status": "active"
}
```

## i18n

New translation keys:

| Key | English | Dutch |
|-----|---------|-------|
| `Print Receipt` | Print Receipt | Bonnetje afdrukken |
| `Email Receipt` | Email Receipt | Bonnetje e-mailen |
| `Receipt Template` | Receipt Template | Bonnetje-sjabloon |
| `Select printer` | Select printer | Printer kiezen |
| `Select template` | Select template | Sjabloon kiezen |
| `Print preview` | Print preview | Afdruk-voorbeeld |
| `Printer offline` | Printer offline | Printer offline |
| `Receipt sent successfully` | Receipt sent successfully | Bonnetje verzonden |
| `Error: {error}` | Error: {error} | Fout: {error} |

## Files Changed

### New Files

- `src/Models/ReceiptTemplate.php` — Eloquent model
- `src/Models/ReceiptPrintLog.php` — Eloquent model
- `src/Services/PosReceiptPrinter.php` — ESC/POS driver
- `src/Services/PosReceiptMailer.php` — Email service
- `src/Controllers/Api/PosReceiptController.php` — REST API
- `src/Views/Components/ReceiptTemplateForm.vue` — CRUD component
- `src/Views/Components/PrintReceiptModal.vue` — Print action modal
- `src/Views/Components/EmailReceiptModal.vue` — Email action modal
- `l10n/en.json` — English translations (receipt keys)
- `l10n/nl.json` — Dutch translations (receipt keys)

### Modified Files

- `pipelinq_register.json` — add `receiptTemplate` and `receiptPrintLog` schemas
- `src/Views/TransactionDetail.vue` — add Print/Email buttons
- `src/Controllers/Api/PosTransactionController.php` — add transaction.receipts array

## Seed Data

### receiptTemplate (3 objects)

**Template 1: Standard Receipt (42 chars wide)**
```json
{
  "id": "rec-tmpl-001",
  "name": "Standaard Bonnetje",
  "description": "Standard POS receipt for all transactions",
  "body": "{% autoescape false %}...[Twig template]...{% endautoescape %}",
  "layoutWidth": 42,
  "isInvoiceMode": false,
  "status": "active"
}
```

**Template 2: Legal Invoice Receipt (80 chars wide, with BTW)**
```json
{
  "id": "rec-tmpl-002",
  "name": "Juridische Factuur (EUR 100+)",
  "description": "Invoice receipt for transactions >= EUR 100 with full BTW compliance",
  "body": "{% autoescape false %}...[Twig template with tax breakdown]...{% endautoescape %}",
  "layoutWidth": 80,
  "isInvoiceMode": true,
  "status": "active"
}
```

**Template 3: F&B Receipt (thermal, compact)**
```json
{
  "id": "rec-tmpl-003",
  "name": "Horeca Bonnetje Compact",
  "description": "Compact receipt for F&B with item categories",
  "body": "{% autoescape false %}...[F&B-specific template]...{% endautoescape %}",
  "layoutWidth": 42,
  "isInvoiceMode": false,
  "status": "active"
}
```

### receiptPrintLog (3 example objects)

```json
{
  "id": "log-001",
  "transaction": "txn-001",
  "template": "rec-tmpl-001",
  "action": "print",
  "printerDevice": "192.168.1.100:9100",
  "status": "success",
  "printedAt": "2026-05-21T14:30:22Z"
}
```

```json
{
  "id": "log-002",
  "transaction": "txn-002",
  "template": "rec-tmpl-001",
  "action": "email",
  "emailRecipient": "klant@example.nl",
  "status": "success",
  "printedAt": "2026-05-21T14:35:10Z"
}
```

```json
{
  "id": "log-003",
  "transaction": "txn-003",
  "template": "rec-tmpl-002",
  "action": "print",
  "printerDevice": "192.168.1.100:9100",
  "status": "failed",
  "errorMessage": "Connection timeout after 5s",
  "printedAt": "2026-05-21T14:40:00Z"
}
```
