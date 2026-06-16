# Tasks — pipelinq-bookkeeping-to-shillinq

> Cross-app contract #3: bookkeeping/billing/accounting → shillinq.
> ADR-019 registry dispatch, ADR-022 leaf/abstraction boundary, ADR-012 dedup.

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm three registry-mediated pipelinq→shillinq dispatchers already exist
      (`pipelinq-project-to-shillinq-ledger`, `pipelinq-time-to-shillinq-wip`,
      `pipelinq-expense-to-shillinq-ap`) and that this change REUSES that pattern
      rather than adding a fourth parallel dispatcher.
- [x] Confirm `PosBookkeepingService` is the only remaining surface that OWNS
      bookkeeping artefacts (`glAccountMapping`, `posJournalEntryOutbound`) and
      posts to a hard-coded `/api/JournalEntry` (bypassing ADR-019).
- [x] Confirm `posZReport` is operational commercial state (cash-drawer close /
      takings) and stays in pipelinq — only the journal-entry consequence delegates.
- [x] Confirm `BillingApproval` is already an href to shillinq (no duplicate
      billing UI is being built here).

## Phase 1: Nav / IA correction (ADR-022)

- [x] Remove the `ZReports` ("Boekhoudkundige Afhandeling") entry from the
      `menu` array in `src/manifest.json`.
- [x] Remove the `PosBookkeepingSettings` ("POS bookkeeping") admin entry from
      the `menu` array in `src/manifest.json`.
- [x] Keep the `ZReports` (`/pos/z-reports`) and `ZReportDetail`
      (`/pos/z-reports/:id`) page definitions routable in the `pages` array.
- [x] Remove the `PosBookkeepingSettings` page (`/admin/pos-bookkeeping`) from the
      `pages` array and delete `src/views/admin/PosBookkeepingSettings.vue`.
- [x] Relabel `BillingApproval` to `Timesheet approval` and resolve its href
      through the integration registry instead of the hard-coded
      `/index.php/apps/shillinq/` path.

## Phase 2: Delegate the journal entry via the ADR-019 registry

- [x] Replace the hard-coded `rtrim($endpoint,'/').'/api/JournalEntry'` POST in
      `PosBookkeepingService` with a registry-resolved `shillinq.JournalEntry.raise`
      dispatch (endpoint/auth/idempotency resolved from the integration registry,
      matching the project-ledger / WIP / AP dispatch pattern).
- [x] Remove the local GL-balancing build (VAT→GL line construction) from
      `PosBookkeepingService`; send the Z-report `taxBreakdown` (business facts) and
      let shillinq build the journal.
- [x] Preserve the deterministic idempotency key `SHA256(zReport.uuid + reportDate)`
      so re-raises resolve to the same shillinq journal.
- [x] Add a thin outcome projection to `posZReport`: `bookkeepingStatus`
      (pending/raised/failed) and `shillinqJournalEntryId`.

## Phase 3: Retire owned bookkeeping schemas

- [x] Remove the `glAccountMapping` schema from
      `lib/Settings/register.d/50-pos-end-of-day-bookkeeping.json` (GL chart is
      shillinq's). Do NOT delete existing `glAccountMapping` objects (no data loss).
- [x] Remove `posJournalEntryOutbound` as a persisted schema; it becomes an
      ephemeral dispatch payload. Collapse its delivery/retry state into the
      `posZReport` projection fields.
- [x] Add the `bookkeepingStatus` + `shillinqJournalEntryId` fields to the
      `posZReport` schema in the same register fragment.

## Phase 4: Migration (idempotent, fail-safe — lib/Repair)

- [x] Add `lib/Repair/MigratePosBookkeepingToShillinq` using
      `setRegister(slug)->setSchema(Name)->findAll([])` and POSITIONAL OCP args.
- [x] For `posJournalEntryOutbound` records with status `pending`/`failed`/`staged`:
      re-raise via the registry path with the SAME deterministic idempotency key so
      shillinq de-duplicates against any already-created journal.
- [x] For records already `posted`: copy `shillinqJournalEntryId` and
      `bookkeepingStatus: raised` onto the parent `posZReport`; leave the outbound
      record read-only (never drop).
- [x] Document the one-off manual export of `glAccountMapping` profiles into
      shillinq's GL configuration.
- [x] Ensure the repair step is re-runnable and never fails the upgrade if shillinq
      is unreachable (logs + leaves `bookkeepingStatus: pending`).

## Phase 5: i18n + verification

- [x] Update i18n (nl + en) for the relabelled `Timesheet approval` entry and the
      `bookkeepingStatus` projection labels; remove obsolete bookkeeping/GL strings.
      Keys are ENGLISH source strings.
- [x] `npm run build` produces zero errors.
- [x] `cd pipelinq && openspec validate pipelinq-bookkeeping-to-shillinq --strict`
      passes.
