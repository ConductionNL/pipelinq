# Design: clients-bulk-edit-and-undo

## Context (read at pipelinq development cfe0a0a51, OpenRegister development 555af7212, nextcloud-vue 2.57.1)

- **Clients and Contacts pages.** `src/manifest.json` declares `Clients`
  (`/clients`, type `index`, register `pipelinq`, schema `client`) and `Contacts`
  (`/contacts`, schema `contact`). Neither sets `selectable` or `bulkActions`, so
  no selection strip is shown.
- **The index page already supports named bulk actions.** nextcloud-vue
  `CnIndexPage.vue:2476` takes a `bulkActions` prop; `onBulkAction` (around
  :4709) resolves `handler: 'open-modal'` with a `target` to a registered modal
  and passes it `selectedIds` and `count`. `CnPageRenderer.vue:1195` forwards the
  manifest `config` (and `actionToggles`) to the index page, so the switch is
  declarative.
- **OpenRegister runs bulk writes as jobs.** `appinfo/routes.php:1291-1302`
  exposes `bulkJobs#actions`, `#create`, `#commit`, `#cancel`, `#members` and
  `#reverse`. `BulkJobsController::create()` (:204) takes `action`,
  `parameters`, `selection` (`ids` or `query`, read by
  `BulkSelectionResolver`), `justification`, `register` and `schema`, and
  "rehearses the action and writes nothing". `SetPropertiesAction::ID` is
  `openregister:set-properties` and needs `parameters.properties`.
  `AbstractPropertyWriteAction` implements `ReversibleBulkActionInterface` with
  `REVERSAL_WINDOW = 604800` (seven days). `BulkJobReversal::reverse()` builds a
  new job that writes the prior values back, previewed and not yet committed,
  and refuses with reason `window-expired` after the window.
- **Listing a person's jobs.** `BulkJobsController::index()` (:137) returns the
  caller's own jobs (`findByActor`), optionally by `state`; there is no schema
  filter, so the page filters on the job's `register` and `schema` fields in the
  client.

## Decisions

### D1. OpenRegister owns the write and the undo

pipelinq adds no service and no controller. The modal calls
`POST /apps/openregister/api/bulk-jobs` with `action:
openregister:set-properties`, then `POST .../bulk-jobs/{id}/commit`. Undo calls
`POST .../bulk-jobs/{id}/reverse`, shows the returned preview and commits it.
This is ADR-022: the job, its ceilings, its audit and its reversal already
exist, and a pipelinq copy would be a second truth about what changed.

### D2. One property per bulk change

The dialog writes one property at a time. The property list is the schema's
writable, non-computed properties, excluding identifiers (`contactsUid`) and
anything the schema marks read only. One property keeps the preview readable
and matches every competitor's first step.

### D3. Always preview, then commit

The dialog never commits in the same click as create. It shows "N records will
change, M already have this value" from the job's counts and a Confirm button.
A refused job (`BulkJobRefusedException` answered as a refusal body) shows the
refusal reason in the dialog.

### D4. Bulk changes page

A custom page `BulkChanges` (`/bulk-changes`) lists the caller's jobs whose
schema is the pipelinq `client` or `contact` schema id, newest first: when,
which property, how many records, state, and an Undo action while
`reversibleUntil` is in the future. Past the window the row says until when it
could be undone and offers nothing.

### D5. The seven-day window is OpenRegister's

The row asks for "weeks later". The window is a constant in OpenRegister
(`AbstractPropertyWriteAction::REVERSAL_WINDOW`). This change shows the real
window to the user and does not promise more. A configurable window is a
follow-up for OpenRegister, named in the hand-back of the OpenSpec pass.

## Risks

- A selection of "all filtered results" can be large. OpenRegister's job
  ceilings (`BulkJobGuards`) refuse over the limit; the dialog shows the
  refusal rather than splitting the job.
- A contact edited by hand after the bulk change: the reversal writes the prior
  value back over the hand edit. OpenRegister's reversal preview lists the
  members it will skip or overwrite; the dialog shows that list before commit.
