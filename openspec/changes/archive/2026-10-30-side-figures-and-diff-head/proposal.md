---
kind: code
---

# Proposal: side-figures-and-diff-head

## Summary

pl4 PqKassadienst 2 and 6, PqLead 5. The boards set the opening float ("€ 100,00") and the win chance ("62%") as 26px figures in their side cards; live drew them at the 15px title size. The facts widget's `title` entry already takes `size` in px, so both take `size: 26`.

The board heads the Kasverschil card with the muted line "Afstemming" and no pill while the difference waits for a manager: the error notice and the Approve and Reject buttons already say it is pending. The card takes `subtitle: "Drawer reconciliation"` (nl "Afstemming") and its header pill maps `pending` to an empty label, which draws no pill. Approved and Rejected keep their pills.

Configuration only, no PHP.
