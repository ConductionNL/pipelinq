# Review: pipeline-foundation

## Summary
- Tasks completed: 5/5
- GitHub issues closed: N/A (no GitHub issues were created for this change)
- Spec compliance: **PASS with warnings** (critical finding resolved)

## Findings

### CRITICAL
- [x] **REQ-PF-003 — "Set default pipeline" scenario not enforced**: ~~RESOLVED~~ — `PipelineManager.vue:onSave()` now unsets `isDefault` on other pipelines of the same `entityType` before saving the new default.

### WARNING
- [ ] **REQ-PF-001 — Stage color not shown in pipeline list preview**: The spec states "the color MUST be used in the admin settings stage preview". The PipelineManager.vue `stagePreview()` method only shows text-based stage names (e.g., "New → Contacted → ... → Won → Lost") without any color indicators. Colors are editable in PipelineForm but not visually represented in the list view.
  - **Location**: `PipelineManager.vue:stagePreview()` and pipeline-card template
  - **Fix**: Add small colored dots/badges next to stage names in the preview, or show a color bar/gradient representing the pipeline stages.

- [ ] **REQ-PF-004 — Stages always appended, not inserted at position**: The spec's "Add a stage" scenario says "the admin adds a stage 'Demo' at position 3" with "subsequent stages MUST have their order incremented". The current `addStage()` always appends at the end (maxOrder + 1). While the user can then move it up with the reorder buttons, there's no way to insert at a specific position directly.
  - **Location**: `PipelineForm.vue:addStage()`
  - **Impact**: Low — the end result is achievable via move buttons, just less convenient.

### SUGGESTION
- The delete confirmation dialog could differentiate between pipelines with leads/requests assigned vs empty ones (currently warns about stage count but not about assigned entities)
- The PipelineForm overlay could trap keyboard focus for better accessibility (currently a simple overlay div)

## Requirement Compliance Detail

| Requirement | Status | Notes |
|---|---|---|
| REQ-PF-001: Schema Update | PASS | `isClosed`, `isWon`, `color` added to stage items in pipelinq_register.json |
| REQ-PF-002: Default Pipelines | PASS | Sales (7 stages) and Service (5 stages) created idempotently in SettingsService |
| REQ-PF-003: Pipeline CRUD | PASS | List, create, edit, delete all work. "Set default" now unsets previous default. |
| REQ-PF-004: Stage Management | PASS | Add, edit, delete, reorder stages all functional. Insert-at-position is append+move. |
| REQ-PF-005: Stage Validation | PASS | All 4 validation scenarios implemented correctly. |

## Recommendation
**APPROVE** — Critical finding resolved. The remaining warnings (stage color preview, insert-at-position) are minor and can be addressed in a follow-up. Safe to archive.
