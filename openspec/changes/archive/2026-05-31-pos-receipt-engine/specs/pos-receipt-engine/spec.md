---
status: draft
---

# Spec: POS Receipt Engine

## Purpose

Enable printing and emailing of receipts from POS transactions to thermal printers and customer email addresses. Receipts are generated from customizable Twig templates with optional legal invoice mode for Dutch VAT compliance on high-value transactions (≥ EUR 100).

---

## REQ-PRE-001: Print receipt to thermal printer [MVP]

When a user triggers "Print Receipt" on a transaction detail view and selects a template and printer, the system MUST render the receipt using the selected template, generate ESC/POS commands, connect to the configured printer, and return success or failure status.

### Scenario: Print successful to available printer

- GIVEN a transaction with €45.50 total and a "Standard Receipt" template
- WHEN the user clicks "Print Receipt" → selects printer "192.168.1.100:9100" → template "Standaard Bonnetje"
- THEN the system MUST:
  - Render the template with transaction data via Twig
  - Generate valid ESC/POS commands (font selection, bold, cut)
  - Connect to the printer and stream the commands
  - Receive a status byte from the printer
  - Display "Receipt printed successfully"
  - Create a receiptPrintLog entry with status=success and action=print

### Scenario: Print fails when printer offline

- GIVEN the same transaction and template, but the printer at 192.168.1.100:9100 is offline
- WHEN the user clicks "Print Receipt" → selects the offline printer
- THEN the system MUST:
  - Attempt connection with 5-second timeout
  - Display "Printer offline" error message
  - Create a receiptPrintLog entry with status=failed and errorMessage="Connection timeout"
  - NOT show a success message

### Scenario: Print creates audit log entry

- GIVEN a successful print
- WHEN the print completes
- THEN a new receiptPrintLog MUST be created with:
  - transaction UUID
  - template UUID
  - action = "print"
  - printerDevice = "192.168.1.100:9100" (or configured device)
  - status = "success"
  - printedAt = current ISO timestamp

---

## REQ-PRE-002: Email receipt to customer [MVP]

When a user triggers "Email Receipt" on a transaction and provides a customer email address, the system MUST render the receipt, format it for email delivery, and submit to Pipelinq Mail queue.

### Scenario: Email receipt successful

- GIVEN a transaction with €45.50 total and "Standard Receipt" template
- WHEN the user clicks "Email Receipt" → enters "klant@example.nl" → confirms
- THEN the system MUST:
  - Render the template with transaction data
  - Format as plain text or HTML per template config
  - Submit to Pipelinq MailerService via sendMail() call
  - Display "Receipt sent successfully"
  - Create a receiptPrintLog with action=email, emailRecipient="klant@example.nl", status=success

### Scenario: Email fails with invalid recipient

- GIVEN a transaction and email form
- WHEN the user enters an invalid email "not-an-email"
- THEN the system MUST:
  - Validate email format client-side before submit
  - Display "Invalid email address" error
  - NOT create a receiptPrintLog entry

### Scenario: Email creates audit log entry

- GIVEN a successful email submission
- WHEN the email is queued in MailerService
- THEN a new receiptPrintLog MUST be created with:
  - action = "email"
  - emailRecipient = the recipient address
  - status = "success"
  - printedAt = current ISO timestamp
  - renderedContent = (optional) the actual email body for audit

---

## REQ-PRE-003: Receipt template management [MVP]

The admin MUST be able to create, read, update, and delete receipt templates with Twig syntax support and live preview.

### Scenario: Create template with default layout

- GIVEN the admin is on Settings → Receipts → Templates
- WHEN the admin clicks "Create Template" → enters name "My Custom Receipt" → saves
- THEN a new receiptTemplate MUST be created with:
  - name = "My Custom Receipt"
  - status = "draft" (not active in production until explicitly published)
  - layoutWidth = 42 (default)
  - body = blank or starter template
  - organization = current user's organization

### Scenario: Edit template body with Twig

- GIVEN an existing template "Standaard Bonnetje"
- WHEN the admin opens the template editor → modifies the Twig body → clicks Save
- THEN the system MUST:
  - Validate Twig syntax before saving
  - Display any syntax errors in an error pane
  - Save the template if syntax is valid
  - Update the updatedAt timestamp

### Scenario: Preview template with sample transaction

- GIVEN a template with Twig body and a sample transaction
- WHEN the admin is editing the template and looks at the Preview pane
- THEN the system MUST:
  - Render the template using a sample transaction
  - Display the rendered receipt in a monospace font preview
  - Update the preview in real-time as the Twig source is edited

### Scenario: Publish template to active status

- GIVEN a template with status=draft
- WHEN the admin clicks "Publish" / "Activate"
- THEN the template status MUST change to active
- AND the template MUST now appear in the template dropdown on transaction detail views
- AND the previous active template (if any) MAY remain or be archived per admin choice

### Scenario: Delete / archive template

- GIVEN an active template
- WHEN the admin clicks "Archive" or "Delete"
- THEN the template status MUST change to archived
- AND it MUST NOT appear in the template dropdown for new prints
- AND existing receiptPrintLog entries MUST retain the archived template reference

---

## REQ-PRE-004: Invoice mode for high-value transactions [MVP]

When a transaction total is ≥ EUR 100, the receipt MUST automatically render in invoice mode with BTW breakdown, compliance metadata, and formal invoice styling.

### Scenario: Standard receipt below EUR 100

- GIVEN a transaction with €75.00 total
- WHEN the user selects "Standaard Bonnetje" template (isInvoiceMode=false) and prints
- THEN the rendered receipt MUST:
  - NOT include BTW breakdown
  - NOT include legal compliance footer
  - Display simple transaction summary and total

### Scenario: Invoice receipt at or above EUR 100

- GIVEN a transaction with €125.00 total, including €21.84 BTW
- WHEN the user selects "Juridische Factuur" template (isInvoiceMode=true) and prints
- THEN the rendered receipt MUST:
  - Include full BTW breakdown per rate (hoog/laag/nul/vrijgesteld)
  - Display compliance footer with organization VAT number, date, and transaction reference
  - Render with 80-character width for invoice formatting
  - Include barcode or reference number for archival

### Scenario: Template enforces invoice mode on high-value transactions

- GIVEN a transaction with €125.00 total and a simple "Standaard Bonnetje" template (isInvoiceMode=false)
- WHEN the system auto-detects transaction >= EUR 100
- THEN the system MUST:
  - Either auto-switch to an isInvoiceMode=true template, OR
  - Log a warning that the selected template does not meet compliance requirements for this amount
  - (Behavior per business rule — may require manager approval)

---

## REQ-PRE-005: Receipt print log audit trail [MVP]

Every print and email action MUST be logged to receiptPrintLog for compliance audit and reprinting.

### Scenario: Print log records device and status

- GIVEN a successful print to printer "192.168.1.100:9100"
- WHEN the print completes
- THEN the receiptPrintLog entry MUST capture:
  - printerDevice = "192.168.1.100:9100" (for device-specific troubleshooting)
  - action = "print"
  - status = "success"
  - printedAt = ISO timestamp of completion

### Scenario: Email log records recipient

- GIVEN a successful email to "klant@example.nl"
- WHEN the email is queued
- THEN the receiptPrintLog entry MUST capture:
  - emailRecipient = "klant@example.nl"
  - action = "email"
  - status = "success"

### Scenario: Failed log entry preserves error message

- GIVEN a print that fails (printer offline)
- WHEN the print attempt completes with error
- THEN the receiptPrintLog entry MUST:
  - status = "failed"
  - errorMessage = "Connection timeout after 5s" (specific, actionable message)
  - printedAt = timestamp of the failed attempt

---

## REQ-PRE-006: ESC/POS printer protocol support [MVP]

The system MUST support standard ESC/POS thermal printers (Star, Epson, AURA) via socket connection and MUST generate valid ESC/POS command sequences.

### Scenario: Connect to printer and detect status

- GIVEN a printer at IP 192.168.1.100, port 9100
- WHEN the system initiates a socket connection
- THEN the system MUST:
  - Connect to the printer within 5 seconds
  - Send an ESC/POS status request command
  - Receive a status byte indicating online/offline/error
  - Return the status to the UI

### Scenario: Generate ESC/POS commands for text formatting

- GIVEN receipt text with formatting requirements (bold, font size, alignment)
- WHEN the system generates ESC/POS commands
- THEN the system MUST:
  - Emit ESC/@ (reset)
  - Emit ESC/E (bold on/off)
  - Emit ESC/d (linefeed count)
  - Emit GS/V (full cut or partial cut) at the end
  - Result MUST be valid binary compatible with Star/Epson firmware

### Scenario: Print respects layout width setting

- GIVEN a template with layoutWidth=42
- WHEN the system renders the template and streams to printer
- THEN the system MUST:
  - Wrap text to 42 characters per line
  - Not emit line breaks that exceed the layout width
  - Center or right-align content per Twig template directives

---

## REQ-PRE-007: Admin settings for receipt configuration [MVP]

Administrators MUST be able to configure default printer, email sender, and default template via admin settings.

### Scenario: Configure printer IP and port

- GIVEN the admin is in Settings → POS Receipts
- WHEN the admin sets "Printer IP" = "192.168.1.100" and "Port" = "9100"
- THEN these values MUST be saved to the settings table
- AND all subsequent print requests MUST default to this printer

### Scenario: Configure email sender

- GIVEN the admin sets "Email Sender Address" = "receipts@company.nl"
- WHEN a receipt is emailed
- THEN the email MUST have From: "receipts@company.nl"

### Scenario: Select default template

- GIVEN the admin sets "Default Template" = "Standaard Bonnetje"
- WHEN a user opens the print/email modal without selecting a template
- THEN the system MUST pre-select "Standaard Bonnetje"

---

## REQ-PRE-008: Transaction detail print/email buttons [MVP]

The transaction detail view MUST expose "Print Receipt" and "Email Receipt" action buttons with modal dialogs for selecting printer and template.

### Scenario: Print Receipt modal workflow

- GIVEN a transaction detail view
- WHEN the user clicks "Print Receipt"
- THEN a modal MUST appear with:
  - Template dropdown (pre-filled with default or active template)
  - Printer dropdown (showing configured printer or allow manual entry)
  - "Preview" pane showing the rendered receipt
  - "Print" and "Cancel" buttons

### Scenario: Email Receipt modal workflow

- GIVEN a transaction detail view
- WHEN the user clicks "Email Receipt"
- THEN a modal MUST appear with:
  - Template dropdown (pre-filled with default)
  - Email recipient input field (with validation)
  - "Preview" pane showing rendered receipt
  - "Send" and "Cancel" buttons

### Scenario: Modal closes on successful action

- GIVEN a modal open (print or email)
- WHEN the action completes successfully
- THEN the modal MUST close automatically
- AND the transaction detail view MUST show a success message

---

## REQ-PRE-009: Twig template rendering with transaction context [MVP]

Receipt templates MUST support Twig template syntax with access to transaction, company, and system variables.

### Scenario: Template accesses transaction data

- GIVEN a Twig template with {{ transaction.total|number_format(2) }}
- WHEN the template is rendered for a transaction with €125.50
- THEN the output MUST contain "125.50"

### Scenario: Template accesses line items loop

- GIVEN a Twig template with:
  ```twig
  {% for line in transaction.lines %}
  {{ line.description }} x{{ line.quantity }} = €{{ line.total|number_format(2) }}
  {% endfor %}
  ```
- WHEN rendered for a transaction with 3 line items
- THEN the output MUST show all 3 lines with description, quantity, and total

### Scenario: Template accesses company metadata

- GIVEN a Twig template with {{ company.name }}, {{ company.address }}, {{ company.phone }}
- WHEN rendered
- THEN the output MUST contain the organization's configured values

### Scenario: Template syntax error is caught

- GIVEN a template with invalid Twig syntax: {{ transaction.nonexistent|undefined_filter }}
- WHEN the template is rendered
- THEN the system MUST:
  - Catch the Twig exception
  - Log the error
  - Display a user-friendly message "Template rendering failed: invalid filter"
  - NOT stream a broken receipt to the printer

---

## REQ-PRE-010: Receipt reprinting for same transaction [MVP]

A user MUST be able to reprint a receipt for a transaction without creating a duplicate entry in the transaction's totals or inventory.

### Scenario: Reprint same template

- GIVEN a transaction that was already printed once
- WHEN the user clicks "Print Receipt" again and selects the same template and printer
- THEN the system MUST:
  - Render and print the exact same receipt
  - Create a NEW receiptPrintLog entry (second print is audited separately)
  - NOT modify the transaction's status or totals

### Scenario: Print with different template

- GIVEN a transaction printed once with "Standaard Bonnetje"
- WHEN the user prints again with "Horeca Bonnetje Compact"
- THEN the system MUST:
  - Render the receipt with the new template
  - Create a new receiptPrintLog with the new template UUID
  - Both entries appear in the audit log for the transaction

---

## REQ-PRE-011: Multi-language support in templates [MVP]

Receipt templates MUST support language variables for multi-language receipts without template duplication.

### Scenario: Template uses i18n keys

- GIVEN a template with {{ t('Thank you for your purchase') }}
- WHEN rendered in Dutch language context
- THEN the output MUST show the Dutch translation "Bedankt voor uw aankoop"

### Scenario: Template respects system locale

- GIVEN a template with {{ transaction.createdAt|date('d-m-Y H:i') }}
- WHEN the system locale is Dutch
- THEN the output MUST use Dutch date format

---

## REQ-PRE-012: Printer device status polling [MVP]

The system MUST be able to check the status of a configured printer without sending a receipt.

### Scenario: Check printer online status

- GIVEN the admin clicks "Test Printer" in Settings
- WHEN the system sends an ESC/POS status request
- THEN the system MUST:
  - Display "Printer online" or "Printer offline" (per status byte)
  - Show device name and firmware if available
  - Indicate any error conditions (no paper, cover open, etc.)

---

## REQ-PRE-013: HTML/PDF receipt format for email [MVP]

Email receipts MUST support both plain-text and HTML/PDF formats per template configuration.

### Scenario: Email plain-text receipt

- GIVEN a template configured for plain-text email
- WHEN a receipt is emailed
- THEN the email body MUST be formatted as plain text
- AND the email MUST not have attachments

### Scenario: Email HTML receipt

- GIVEN a template configured for HTML email with CSS styling
- WHEN a receipt is emailed
- THEN the email body MUST be formatted as HTML with embedded styles
- AND the email MUST render correctly in common email clients

### Scenario: Email receipt as PDF attachment

- GIVEN a template configured for PDF attachment
- WHEN a receipt is emailed
- THEN the system MUST:
  - Generate a PDF from the rendered receipt using dompdf or similar
  - Attach the PDF to the email
  - Display the receipt content both in the email body and as attachment

---

## REQ-PRE-014: Batch reprint from receipt log [MVP]

Administrators MUST be able to reprint receipts from historical print logs for compliance or customer requests.

### Scenario: Reprint from log entry

- GIVEN a receiptPrintLog entry from 2026-05-20 for transaction ABC123
- WHEN the admin navigates to Receipts → Print Log → clicks "Reprint" on the entry
- THEN the system MUST:
  - Retrieve the original transaction and template
  - Render and print the same receipt
  - Create a NEW receiptPrintLog entry marked "reprint" with original timestamp reference

---

## Integration Points

- **posTransaction** — receipt templates and logs are scoped to transactions
- **Pipelinq MailerService** — email submissions use the standard mail queue
- **ESC/POS devices** — thermal printers at configured IP:port
- **Twig template engine** — receipt rendering engine
- **Admin Settings** — configuration storage for printer, email, defaults
