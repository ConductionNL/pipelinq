# Proposal: lead-management enhancements

## Problem

The lead management spec identifies MVP gaps:
1. No contact person picker in LeadForm (REQ-LEAD-001 Scenario 3)
2. No overdue indicator on LeadDetail showing days past expected close date (REQ-LEAD-009 Scenario 41)
3. Lead value not auto-synced from LeadProducts line item totals (REQ-LEAD Products)

## Proposed Change

- Add contact person picker to LeadForm.vue filtered by selected client
- Add overdue indicator to LeadDetail.vue showing days overdue
- Add auto-sync of lead value from product line items total

### Out of Scope
- Stale lead detection (V1)
- Aging indicator (V1)
- Import/export CSV (V1)
- Lead qualification scoring (V1)
- Lead deduplication (V1)

## Impact
- **Files modified**: 2 Vue files (LeadForm.vue, LeadDetail.vue)
- **Risk**: Low
