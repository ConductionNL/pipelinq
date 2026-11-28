---
kind: code
---

# Proposal: organisation-enrichment

## Summary

Board PqOrganisatiesVerrijken: select organisations on the list and choose "Ontbrekende gegevens aanvullen".

- Pipelinq looks each one up in the KvK Handelsregister through OpenRegister's KvK source: by its KvK number when it has one, else by its exact name.
- It proposes the KvK number, the legal form and the place where the organisation lacks them. Where its value differs, the proposal is flagged ("wijkt af") and starts unticked.
- Only ticked proposals are written. Organisations the KvK does not know are named. Proposals can be downloaded as CSV.
- People are never looked up: personal data from the BRP is not filled in bulk.
- Both endpoints need the CRM capability. The write goes through OpenRegister, so the audit trail records it.

## Not in this change

OpenCorporates as a second source, and the address (a mirror of the Nextcloud contact, which stays the authority).

## Depends on

nextcloud-vue `fix/bulk-open-modal-opens-it`: a bulk action's open-modal opens the modal.
