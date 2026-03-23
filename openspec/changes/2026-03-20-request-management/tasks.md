# Tasks: request-management contact linking

## 1. Contact Person Picker in RequestForm

- [ ] 1.1 Add contact picker to RequestForm.vue
  - **spec_ref**: `specs/request-management/spec.md#REQ-RM-120`
  - **files**: `pipelinq/src/views/requests/RequestForm.vue`
  - **acceptance_criteria**:
    - GIVEN a request form with a client selected
    - THEN a contact person picker MUST show contacts belonging to that client
    - AND changing the client MUST clear the contact selection
    - AND the picker MUST be disabled when no client is selected

## 2. Contact Display in RequestDetail

- [ ] 2.1 Display linked contact person in RequestDetail.vue
  - **spec_ref**: `specs/request-management/spec.md#REQ-RM-120`
  - **files**: `pipelinq/src/views/requests/RequestDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a request with a linked contact person
    - THEN the contact name MUST be displayed as a clickable link
    - AND email and phone MUST be shown inline
