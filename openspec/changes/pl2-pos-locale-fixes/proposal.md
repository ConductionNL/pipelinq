---
kind: code
---

# Proposal: pl2-pos-locale-fixes

## Summary

Two strings the till showed did not follow the user (PL-ABSTRACT bug 17 and a Dutch source string):

- The purchase history beside the checkout wrote every total as "€" plus two decimals and every date in nl-NL. It now
  shows money in the purchase's own currency (else the reporting currency) and dates in the user's locale.
- Initiating a payment without a provider or method answered with the Dutch source string
  "Betaalmethode niet geconfigureerd". The source string is now English ("Choose a payment method."), with the Dutch
  translation in l10n.
