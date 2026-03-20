# Delta Spec: lead-management enhancements

## Changes to specs/lead-management/spec.md

### Newly Implemented

- **REQ-LEAD-001 Scenario 3 (Contact linking)**: Contact person picker added to LeadForm.vue, filtered by selected client. Picker disabled when no client selected, clears on client change.
- **REQ-LEAD-009 Scenario 41 (Overdue indicator)**: LeadDetail.vue now shows a red "X days overdue" badge when `expectedCloseDate` is in the past and the lead is not in a closed stage (won/lost).
- **Lead Products value auto-sync**: Already implemented via `syncLeadValue` and `onProductValueChanged` handlers in LeadDetail.vue -- corrected from previous assessment.
