---
kind: code
---

# Proposal: pos-sale-in-shift

## Summary

- **A sale knows its shift.** A transaction gets a `shift` reference to the cash shift that was open at its till when the sale was confirmed. Without a till on the sale, the cashier's own open shift is used. No open shift leaves the reference empty.
- **The shift page counts its sales.** The shift page shows how many sales were confirmed in it ("38 transacties in deze dienst" on board PqKassadienst).
- **The shift reads by name.** The shift list and the shift page name a shift by when it opened and its drawer ("Dienst 6 okt, Balie 3", boards PqKassadiensten and PqKassadienst). They use nextcloud-vue's column `template` and the detail `titleTemplate`. With an older library the list shows the reference.

## Not in this change

The cash difference still sums sales by the shift's time window. Summing by the `shift` reference is a follow-up.
