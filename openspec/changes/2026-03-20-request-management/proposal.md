# Proposal: request-management contact linking

## Problem

The request management spec (REQ-RM-120) requires linking requests to specific contact persons at client organizations. The `contact` field exists in the OpenRegister schema but the RequestForm does not include a contact person picker, and the RequestDetail does not display the linked contact.

## Proposed Change

Add contact person picker to RequestForm.vue filtered by selected client, and display linked contact information on RequestDetail.vue.

### Scope
- Contact person picker in RequestForm, filtered by selected client
- Contact picker disabled when no client is selected
- Contact cleared when client changes
- Contact person display on RequestDetail with clickable link

### Out of Scope
- Faceted filtering (V1)
- Bulk operations (V1)
- Request templates (V1)
- SLA tracking (Enterprise)
- Reporting/KPIs (V1)

## Impact
- **Files modified**: 2 Vue files (RequestForm.vue, RequestDetail.vue)
- **Risk**: Low -- adding a new field to existing form and detail views
