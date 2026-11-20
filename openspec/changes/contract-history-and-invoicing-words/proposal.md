---
kind: code
screens: [PqContract]
---

# Proposal: contract-history-and-invoicing-words

## Summary

Board PqContract tells the contract's history in words: "Status gewijzigd naar Verloopt binnenkort", "Verlengingslead
aangemaakt", "Naar facturering gestuurd, INV-2026-0187". Live showed the raw audit actions. The header action reads
"Naar facturering sturen" on the board and "Verzenden naar facturering" live. The board draws "Verlengingslead openen"
without an icon.

## What changes

- Both History cards (the side card and the History tab) take the Dq history pattern: stacked, compact, `actionLabels`
  (create "Created by {actor}", update "Updated"), `changeLabels` for status, renewal lead and invoice reference, and
  `valueLabels` that put the status in the words of the status pill. Row size stays the Dq compact row (Dq wins where
  the Pq and Dq boards differ).
- nl "Send to invoicing" becomes "Naar facturering sturen".
- The `open-renewal-lead` action loses its icon.
