# Tasks: clients-bulk-edit-and-undo

## 1. Selection and the bulk action

- [ ] 1.1 Set `selectable: true` and add `bulkActions: [{id: "change-field", label: "Change a field", handler: "open-modal", target: "BulkChangeFieldModal"}]` on the Clients and Contacts pages in `src/manifest.json`
  - Verify: manifest validates (`npm run lint` runs the manifest schema check); on `/clients` ticking two rows shows the selection strip with Change a field
- [ ] 1.2 Add `src/modals/BulkChangeFieldModal.vue` (NcDialog based) and register it in `src/registry.js`: property picker (NcSelect with `inputLabel`), value input typed from the schema property, Preview button
  - Verify: Vitest mounts the modal with a client schema and lists only writable properties

## 2. Preview and commit through OpenRegister

- [ ] 2.1 Preview creates the job: `POST /apps/openregister/api/bulk-jobs` with `action: openregister:set-properties`, `parameters.properties`, `selection.ids`, `register` and `schema`; show the change and skip counts from the response
  - Verify: Vitest with a mocked axios asserts the request body and that nothing is committed on Preview
- [ ] 2.2 Confirm commits: `POST .../bulk-jobs/{id}/commit`; close the dialog and refresh the list
  - Verify: Playwright `tests/e2e/clients-bulk-change.spec.ts` sets industry on three clients and reads the new value in the table
- [ ] 2.3 Show a refusal: a 4xx refusal body renders its reason in the dialog and nothing is committed
  - Verify: Vitest with a refusal response

## 3. Bulk changes page and undo

- [ ] 3.1 Add custom page `BulkChanges` (`/bulk-changes`) and its menu entry; list `GET .../bulk-jobs` filtered to the client and contact schema ids, newest first
  - Verify: Playwright opens Bulk changes after 2.2 and sees the job with its property and count
- [ ] 3.2 Undo: `POST .../bulk-jobs/{id}/reverse`, show the reversal preview (members to write and to skip), commit on confirm
  - Verify: Playwright undoes the job from 2.2 and reads the old industry values back
- [ ] 3.3 Past `reversibleUntil`, show the date and no Undo action
  - Verify: Vitest with a job whose window has passed

## 4. Text and docs

- [ ] 4.1 English source strings and Dutch translations in `l10n/`, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 4.2 User doc page `docs/Features/bulk-changes.md`: select, change, undo, and the seven-day window
  - Verify: docs build exit 0
