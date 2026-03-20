# Tasks: lead-management enhancements

## 1. Contact Person Picker

- [ ] 1.1 Add contact person picker to LeadForm.vue
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LEAD-001`
  - **files**: `pipelinq/src/views/leads/LeadForm.vue`
  - **acceptance_criteria**:
    - GIVEN a lead form with a client selected
    - THEN a contact picker MUST show contacts for that client
    - AND the picker MUST be disabled when no client is selected
    - AND changing the client MUST clear the contact selection

## 2. Overdue Indicator

- [ ] 2.1 Add overdue indicator to LeadDetail.vue
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LEAD-009`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a lead with expectedCloseDate in the past and in a non-closed stage
    - THEN a red "X days overdue" indicator MUST be shown
    - AND leads without expectedCloseDate MUST NOT show the indicator

## 3. Value Auto-Sync

- [ ] 3.1 Implement syncLeadValue handler in LeadDetail.vue
  - **spec_ref**: `specs/lead-management/spec.md#REQ-LEAD Products`
  - **files**: `pipelinq/src/views/leads/LeadDetail.vue`
  - **acceptance_criteria**:
    - GIVEN line items are modified in LeadProducts
    - WHEN the sync-value event fires
    - THEN the lead's value MUST be updated to match the line item total
