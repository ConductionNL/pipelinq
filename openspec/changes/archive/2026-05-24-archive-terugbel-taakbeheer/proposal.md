# Proposal: Archive terugbel-taakbeheer (merge unique REQs into callback-management)

## Summary

Archive the draft `terugbel-taakbeheer` capability. Coverage scan (2026-05-24) identified it as a duplicate of the already-implemented `callback-management` capability. The 9 REQs in `terugbel-taakbeheer` overlap with `callback-management` (12 REQs, code-complete) and `my-work` (which already covers personal task-inbox integration).

Four REQs in `terugbel-taakbeheer` describe scope NOT covered by `callback-management`:
1. Citizen-facing status notifications (V1)
2. Task templates with usage stats (V1)
3. Manager-level cross-department task search and dashboard (V1)
4. Deadline business-hours calculation + bulk reassignment (V1)

These four REQs are merged into `callback-management/spec.md` (marked `**Source**: Merged from archived terugbel-taakbeheer spec (2026-05-24)`) so the unique scope is preserved as the canonical capability owner.

## Motivation

- `pipelinq/openspec/coverage-report.md` bucket 3b explicitly flagged `terugbel-taakbeheer` as "duplicates callback-management" and recommended archive or merge.
- Keeping both capabilities creates spec ambiguity: every callback REQ has two homes, and future annotation passes (`opsx-annotate`) would have to pick one arbitrarily.
- `callback-management` already has three archived changes (`2026-03-22`, `2026-03-25`, `2026-05-11`) and a pending change directory. It is the established canonical home.

## Scope

### In scope
- Delete `openspec/specs/terugbel-taakbeheer/` (the draft capability spec).
- Delete `openspec/changes/2026-03-20-terugbel-taakbeheer/` (the duplicate pending change — its scope is fully covered by the pending `callback-management` change plus the merged REQs).
- Append 4 new V1 REQs to `openspec/specs/callback-management/spec.md` covering citizen notifications, templates, manager search/dashboard, and business-hours/bulk-reassignment.

### Out of scope
- Implementing the 4 merged V1 REQs (they are future work, tracked under callback-management).
- Touching the existing `2026-03-21-terugbel-taakbeheer` archived change (history is immutable).
- Modifying `my-work` spec (already includes task integration scenarios).

## Refs

- Umbrella tracking issue: ConductionNL/pipelinq#545
- Coverage report: `pipelinq/openspec/coverage-report.md` (bucket 3b note 2)
- Canonical capability: `openspec/specs/callback-management/spec.md`
