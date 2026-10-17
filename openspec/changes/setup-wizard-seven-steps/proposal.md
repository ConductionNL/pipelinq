# Proposal: setup-wizard-seven-steps

## Why

Board PqInstellen draws the setup wizard in seven steps: Welkom, Voorbeeldgegevens, Valuta, Organisatie, Kassa, Integraties, Klaar. Pipelinq had five; Kassa and Integraties were missing.

## What changes

- A `pos` step (config-fields): the sender address for emailed receipts and the receipt printer's address and port, the keys the till already reads (`receipt_email_sender`, `receipt_printer_host`, `receipt_printer_port`). All optional.
- An `integrations` step (info) that says Pipelinq detects Shillinq, xWiki and integriq itself and where the admin settings show the result. It asks for nothing, so REQ-SETUP-PIP-006 (no base URLs) still holds.
- `GET /api/setup/status` reports both steps, always done, so neither keeps the wizard open.
- The first three steps take the board's short names (Welcome, Example data, Currency), so the step strip reads as the board's.
