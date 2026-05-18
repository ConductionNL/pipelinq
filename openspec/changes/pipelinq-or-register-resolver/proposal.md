# pipelinq: adopt RegisterResolverService

## Why

Split from the parent `pipelinq-adopt-or-abstractions` change per ADR-032
(spec-sizing cap: ≤20 unchecked tasks per change). See
`openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/`
for the original bundled proposal and design.

This slice covers Phase 1 only: the eight `$appConfig->getValueString(APP_ID,
'register', '')` call sites flagged in `.claude/audit-2026-05-03/04-hardcoded.md`.
This is the single biggest find-and-replace win in the pipelinq backend cleanup
and is intentionally carved off so it can land as one apply pass.

## What Changes

### Register-resolver consumption (Phase 1)

1. Migrate eight `$appConfig->getValueString(APP_ID, 'register', '')` call sites
   to `RegisterResolverService` per the OR-side `register-resolver-service` spec.
2. Verify zero remaining direct register reads via grep across `lib/`.

## Affected Projects

- pipelinq (consumer)
- openregister (must ship `register-resolver-service`)

## Impact

- Affected code (apply-phase hints, NOT changed here):
  `lib/Service/QueueService.php` (4 sites),
  `lib/Service/DefaultQueueService.php` (2 sites),
  `lib/Service/ContactVcardService.php`,
  `lib/Service/ContactVcardWriterService.php`.
- Affected specs: new `pipelinq-or-adoption` capability slice (delta only).
- Breaking changes: none — on-wire register IDs unchanged.
- Dependencies: OR ships `register-resolver-service` (gate).

## See Also

- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/proposal.md`
  — parent bundled proposal.
- `openspec/changes/archive/2026-05-18-pipelinq-adopt-or-abstractions-split/design.md`
  — full design (Decision 1).
- `.claude/audit-2026-05-03/04-hardcoded.md` — audit source.
