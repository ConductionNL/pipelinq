---
kind: code
---

# Proposal: pl2-cash-register-audit

## Summary

- **The audit entry is configuration.** KassakoppelingAuditDetail.vue drew one audit entry from
  `/api/kassakoppeling/audit/{id}`. It is a `type: "detail"` page with `entitySource: "endpoint"`: facts cards for the
  verification status, the summary (amounts stored in cents) and the cryptographic data with copy buttons and a link to the
  transaction, and Verify signature as a header action while the entry is not verified.
- **UI-facing names in English (PL-ABSTRACT Q16).** The address is `/cash-register-audit` (the old `/kassakoppeling/audit`
  addresses redirect), the menu reads "Cash register audit", the list file is `CashRegisterAuditList.vue` and the export
  dialog `TaxAuthorityExportDialog.vue`, with English source strings ("Export for the tax authority"). Page ids, the
  backend endpoint and the schema keep their names for the separate rename programme.

The list stays a component for now: it needs a date-range filter the endpoint index does not offer yet.

## Dependency

nextcloud-vue `feature/pl-abstract-pl2-detail-endpoint` (the endpoint record source and facts `minorUnits`).
