# Tasks — pipelinq: archival + calculation annotation migration

ADR-032 cap respected (≤20 unchecked tasks).

Schema-declarative change (ADR-031): behaviour lives in
`lib/Settings/pipelinq_register.json` as `x-openregister-archival` /
`x-openregister-calculations` annotations, consumed by OpenRegister at runtime
(NOT dormant — see Runtime notes).

## Runtime status (not dormant)

OpenRegister already ships the engines that consume both annotations from a
schema's `configuration` block:

- **Calculations** — `CalculationOnSaveListener` materialises `materialise:true`
  calculations on save; `RenderObject::applyVirtualCalculations` evaluates
  `materialise:false` calculations on every read. Save-time shape is validated by
  `CalculationAnnotationValidator`. Verified: all pipelinq calculations validate
  with 0 errors against that validator.
- **Archival** — `RetentionEvaluator` / `RetentionConditionEvaluator` +
  `ArchivalRetentionTask` (cron) evaluate `x-openregister-archival.retention`;
  `RenderObject` surfaces the computed disposition date. Save-time shape is
  validated by `ArchivalAnnotationValidator`. Verified: all pipelinq archival
  blocks validate with 0 errors.

So the annotations take effect once the register is re-imported (version bumped
0.2.17 → 0.2.18 to trigger re-import). The only genuinely deferred item is the
**human DPO sign-off** on the exact retention periods (task 4.1) — the periods
declared are conservative, Archiefwet-grounded defaults pending that review, and
each rule's `reason` says so.

## Honest scope mapping (proposal hints were partly stale)

The proposal framed this as "replacing inline archival/calculation logic". Audit
of the current pipelinq codebase found **no inline archival or lead-calculation
logic to migrate** — qualification-score / staleness / aging / lead-value are V1
spec scenarios not yet implemented in service code (`ProspectScoringService`
scores prospect *companies* for ICP fit, a different concept). This change
therefore implements the ADR-031 **intent declaratively-first**: it lands the
behaviour as schema annotations so the V1 features are schema-driven from day one
rather than growing a service. Spec line hints (505/519/924/1024) were close but
the file is exactly 1024 lines; the real anchors are the lead-management
Requirements (Staleness ~510, Aging Indicator ~510, Lead Qualification Scoring
~634, Lead Products / value-from-line-items ~908).

## Phase 4 — archival annotation

- [x] 4.1 Inventory pipelinq schemas that need Archiefwet retention. Mapped the
      proposal's three categories to real schemas: **kennisbank versions →
      `kennisartikel`**, **task history / callback logs → `task`** (callbacks are
      `type: terugbelverzoek` tasks), **contact/callback log → `contactmoment`**
      (VNG Klantinteracties record). No `emailLink`/`calendarLink` retention added
      — those are external-system sync mirrors, not statutory records. DPO
      sign-off on the exact periods is PENDING and noted in every rule `reason`.
- [x] 4.2 Added `x-openregister-archival.retention` to `kennisartikel`, `task`,
      and `contactmoment` (in each schema's `configuration` block, alongside
      lifecycle). Conservative defaults: kennisartikel `P7Y` (archived versions),
      task `P2Y` (completed), contactmoment `P2Y` (resolved), each with
      condition-based rules + reasons. Validated against
      `ArchivalAnnotationValidator` (0 errors).

## Phase 5 — calculation annotation

- [x] 5.1 Qualification score resolved as a **backend** calculation
      (`x-openregister-calculations.qualificationScore`, `materialise:true`) on the
      lead schema, mirroring the spec's default scoring criteria; frontend reads
      the stored field. Added a backing `qualificationScore` integer property. The
      lead-management spec "frontend vs backend" question is resolved in favour of
      backend (documented in the property + calc `description`; spec section
      updated, see below).
- [x] 5.2 Staleness declared as `daysSinceActivity` (`materialise:false`,
      read-time) diffing `@self.updated` (last save = last activity proxy) against
      `now`. A consumer flags "stale" when it exceeds the configured threshold.
- [x] 5.3 Aging declared as `daysInStage` (`materialise:false`) diffing a new
      `stageEnteredAt` property against `now`, falling back to `@self.created` when
      the stage-change writer has not yet populated `stageEnteredAt` (the input
      field is wired structurally; the writer that sets it on stage change is a
      separate UI concern — noted honestly, the engine is live).
- [x] 5.4 Lead-value: added `weightedValue` (`value * probability / 100`) as the
      single-object derived figure feeding the Pipeline Value KPI. The spec's
      "lead value from line-item sum" is a **cross-schema** aggregation over
      `leadProduct` rows and is NOT expressible as a single-object calculation —
      kept as a service/frontend concern per ADR-031 exception (2), documented in
      the calc `description`.

## Spec update

- [x] Updated `openspec/specs/lead-management/spec.md` Lead Qualification Scoring
      requirement to state the score is a backend
      `x-openregister-calculations.qualificationScore` materialised on save (frontend
      reads it), removing the implicit frontend-vs-backend ambiguity and citing the
      pipelinq-or-adoption Requirement.

## Test

- [x] Extended `tests/Unit/Settings/RegisterAnnotationsTest.php` with
      `testCalculationAnnotationsAreWellFormed` (operator vocabulary + prop/@self
      reference resolution, mirroring `CalculationAnnotationValidator`) and
      `testArchivalAnnotationsAreWellFormed` (retention shape + ISO-8601 duration
      validity, mirroring `ArchivalAnnotationValidator`). 9 tests / 532 assertions
      green; phpcs clean.
