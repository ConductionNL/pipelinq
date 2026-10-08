# Tasks: kcc-agent-panel

> A shared contact-centre panel fed by integriq's `kcc-cti-adapter`
> (ADR-032 `kind: code`). Implementation is out of scope for this change
> (see proposal.md); tasks are recorded so a later change has a plan to
> pick up, not to be built now.

## Implementation tasks

### Task 1: Mirror integriq's `CallEvent` into `kccCall`
- **spec_ref**: `openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once`
- **files**: `lib/Settings/register.d/<nn>-kcc-agent-panel.json` (new
  `kccCall` schema, register `pipelinq`), `lib/Controller/KccCallController.php`,
  `lib/Service/KccCallResolverService.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - `POST /api/kcc/call-events` accepts integriq's CloudEvent shape and
    persists one `kccCall` per `callId`+`kind`, idempotent on repeat
    delivery
  - "In pipelinq" and "Elsewhere" matches (D2/D4) are resolved once, at
    write time, and stored on the record
  - `kccCall` rows older than 30 days are dropped by a scheduled job,
    mirroring integriq's own retention
- [ ] Implement
- [ ] Test

### Task 2: The agent panel page
- **spec_ref**: `openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-panel-shows-the-caller-and-their-open-items`
- **files**: `src/manifest.json` (page `KccAgentPanel`), a component for the
  live call/identity/items view, `tests/vitest/kccAgentPanel.spec.js`
- **acceptance_criteria**:
  - The panel subscribes to the `pipelinq-kccCall` collection and reflects
    a new call without a manual refresh
  - An unmatched caller renders "Unknown caller" and no items, not a hidden
    or errored section
  - Each "Elsewhere" match links to its source app's own page; no shared
    component or iframe is introduced
  - `?callId=` focuses one call; its absence shows the newest active one
- [ ] Implement
- [ ] Test

### Task 3: Cross-app link convention documented
- **spec_ref**: `openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-another-app-links-in-with-a-plain-url`
- **files**: `docs/` (pipelinq's own integration doc for this panel),
  `tests/vitest/kccAgentPanel.spec.js`
- **acceptance_criteria**:
  - The documented URL and its `?callId=` parameter are covered by a test
    that constructs the link the way a consuming app would
  - `npm test` and `composer check:strict` pass
- [ ] Implement
- [ ] Test
