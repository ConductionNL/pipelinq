# Proposal: pos-receipt-card

## Why

Board PqKassabon's receipt card shows when and where the receipt was printed, whether it was mailed, and the layout (a
receipt, an invoice from a threshold). The page could read only one print-log record and no mail or layout.

## What changes

- `GET /api/pos-transactions/{id}/receipt/log` answers the newest print and its printer, the number of prints, the newest
  mail sent and its recipient, the layout and the invoice threshold, after the same access check as the preview.
- The receipt card reads it.
