<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: client-management (Client Management)
     This spec extends the existing `client-management` capability. Do NOT define new entities or build new CRUD — reuse what `client-management` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Tasks: contacts-sync

## 0. Deduplication Check

- [ ] 0.1 Verify no overlap with existing OpenRegister services or app code
  - **spec_ref**: `specs/contacts-sync/spec.md`
  - **acceptance_criteria**:
    - Search `openregister/lib/Service/` for any existing contact-organization linking service
    - Search `openspec/specs/` for any prior spec covering inline link/unlink for `contact.client`
    - Confirm finding: no existing service handles inline link/unlink for `contact.client` — `objectStore.saveObject()` is the correct and sufficient primitive
    - Confirm finding: the archived `2026-02-26-contacts-sync` change covered IManager/vCard sync only — no overlap with this UI-level linking feature
    - Document findings in PR description

## 1. ContactLinkDialog Component

- [ ] 1.1 Create `src/components/ContactLinkDialog.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-001`
  - **files**: `pipelinq/src/components/ContactLinkDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the dialog is opened with a `clientId` prop
    - THEN a search input MUST be rendered and functional
    - AND entering a query MUST call `contactStore.findObjects` with the search term
    - AND results already linked to `clientId` MUST be excluded from the list
    - AND clicking "Link" on a result MUST call `contactStore.saveObject({ ...contact, client: clientId })`
    - AND on success the dialog MUST emit `'linked'` and close
    - AND SPDX header MUST be present: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
    - AND all user-visible strings MUST use `this.t('pipelinq', '...')`
    - AND every `await store.action()` MUST be in `try/catch` with user-facing error feedback

## 2. ContactLinkOrganizationDialog Component

- [ ] 2.1 Create `src/components/ContactLinkOrganizationDialog.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-005`
  - **files**: `pipelinq/src/components/ContactLinkOrganizationDialog.vue`
  - **acceptance_criteria**:
    - GIVEN the dialog is opened with a `contactId` prop
    - THEN a search input MUST be rendered for finding client organizations by name
    - AND selecting a client and confirming MUST call `contactStore.saveObject({ ...contact, client: selectedClientId })`
    - AND on success the dialog MUST emit `'linked'` and close
    - AND SPDX header MUST be present: `<!-- SPDX-License-Identifier: EUPL-1.2 -->`
    - AND all user-visible strings MUST use `this.t('pipelinq', '...')`
    - AND every `await store.action()` MUST be in `try/catch` with user-facing error feedback

## 3. ClientDetail.vue: Link Contact and Header Actions Fix

- [ ] 3.1 Add "Link contact" button to contacts panel in `ClientDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-001`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the user is on a client detail page
    - THEN the contacts panel header MUST show both "Add contact" and "Link contact" buttons
    - AND clicking "Link contact" MUST open `ContactLinkDialog` with the current `clientId`

- [ ] 3.2 Fix contacts panel to use `#header-actions` slot (ADR-018 compliance)
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-006`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the contacts panel renders
    - THEN action buttons MUST be in `<template #header-actions>` not `<template #actions>`

- [ ] 3.3 On `ContactLinkDialog` `'linked'` event, refresh the contacts list
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-001`
  - **files**: `pipelinq/src/views/clients/ClientDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact is linked via the dialog
    - THEN the contacts panel MUST reload and show the newly linked contact without a full page refresh

- [ ] 3.4 Import and register `ContactLinkDialog` in `ClientDetail.vue`
  - **acceptance_criteria**:
    - `ContactLinkDialog` MUST be imported and listed in `components: {}`
    - No `@nextcloud/vue` direct imports — use `@conduction/nextcloud-vue`

## 4. ContactDetail.vue: Organization Card

- [ ] 4.1 Replace static Client field with Organization `CnDetailCard` in `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-003`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN a contact with a `client` UUID
    - THEN an "Organization" `CnDetailCard` MUST render showing the organization name as a `router-link` to `ClientDetail`
    - AND the card `#header-actions` MUST contain an "Unlink" button
    - GIVEN a contact without a `client` value
    - THEN the card MUST show an empty state message
    - AND the card `#header-actions` MUST contain a "Link to organization" button
    - AND the static Client info row in the Contact Information card MUST be removed

- [ ] 4.2 Implement unlink action in `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-004`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the user clicks "Unlink"
    - THEN an `NcDialog` confirmation MUST appear (NEVER `window.confirm()`)
    - AND on confirmation `contactStore.saveObject` MUST be called with `client` set to `null`
    - AND only `contact.client` MUST change — all other fields preserved
    - AND on success the Organization card MUST show empty state

- [ ] 4.3 Implement link-to-organization action in `ContactDetail.vue`
  - **spec_ref**: `specs/contacts-sync/spec.md#REQ-CSO-005`
  - **files**: `pipelinq/src/views/contacts/ContactDetail.vue`
  - **acceptance_criteria**:
    - GIVEN the user clicks "Link to organization" on an unlinked contact
    - THEN `ContactLinkOrganizationDialog` MUST open
    - AND on `'linked'` event the Organization card MUST refresh to show the selected organization name

- [ ] 4.4 Import and register new components in `ContactDetail.vue`
  - **acceptance_criteria**:
    - `ContactLinkOrganizationDialog` and `NcDialog` MUST be imported and listed in `components: {}`
    - No `@nextcloud/vue` direct imports — use `@conduction/nextcloud-vue`

## 5. Build and Verify

- [ ] 5.1 Run `npm run build` and verify no errors
  - **acceptance_criteria**:
    - Build completes with 0 errors and 0 warnings

- [ ] 5.2 Pre-commit verification checklist
  - **acceptance_criteria**:
    - SPDX headers present on all new `.vue` files
    - No `@nextcloud/vue` direct imports — only `@conduction/nextcloud-vue`
    - Every component in `<template>` imported and in `components: {}`
    - All `await store.action()` calls wrapped in `try/catch`
    - No `window.confirm()` or `window.alert()` — `NcDialog` used
    - All user-visible strings via `this.t('pipelinq', '...')`
    - No hardcoded Dutch or English strings in templates

- [ ] 5.3 Smoke test all link and unlink flows
  - **acceptance_criteria**:
    - Link existing contact from ClientDetail → contact appears in contacts list
    - Unlink contact from ContactDetail → Organization card shows empty state
    - Link contact to org from ContactDetail → Organization card shows organization name
    - Create new contact from ClientDetail → contact form pre-populates client field
    - Cancel link dialog → no changes saved
    - Cancel unlink confirmation → organization link preserved

## Verification

- [ ] All tasks checked off
- [ ] Smoke test tasks 5.3 verified manually
- [ ] No stub implementations — all [x] tasks are fully working, not placeholders
