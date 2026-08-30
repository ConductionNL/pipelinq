# Design — retrofit activity-timeline

> **Retrofit change.** Tasks describe retroactive annotation, not new implementation work. The code under specification already exists on `development` (`lib/Service/ActivityTimelineService.php`, methods listed in `proposal.md`).

## Context

The `activity-timeline` capability already has a substantial spec in `openspec/specs/activity-timeline/spec.md` covering the public API surface. The coverage scan flagged 8 private helper methods on `ActivityTimelineService` (Bucket 2a) whose observable behavior is not captured in any REQ. These helpers carry real business rules — type filtering, source-type mapping, OR result-shape tolerance, date-range bounds, ISO duration round-tripping — that would silently regress if the helpers were refactored without a spec to test against.

## Approach

Five REQs (REQ-001 through REQ-005), one per cluster of helpers that share an observable behavior:

| REQ | Helpers covered |
|---|---|
| REQ-001 | `normaliseTypes` |
| REQ-002 | `sourceToActivityType` |
| REQ-003 | `querySchema`, `normaliseResultset`, `extractObjectArray` |
| REQ-004 | `withinDateRange` |
| REQ-005 | `isoDurationToSeconds`, `secondsToIsoDuration` |

REQ-003 collapses three helpers because they all implement the same observable contract: "an OR call returns either Entity objects, plain arrays, or something else, and downstream code wants a uniform `array<string,mixed>`." Splitting these into three REQs would inflate without adding test surface.

REQ-005 collapses parse + format because the contract is round-trip: `format(parse(x))` MUST equal a canonical form of `x`, modulo the documented Y/M approximations.

## Granularity decisions

- **REQ-001 silent fall-through**: `normaliseTypes` returns `null` (= "no filter") when given all-unknown input. This is observed behavior and is specified as-is. Flagged in Notes — a caller expecting strict matching would be surprised. Not silently "fixed" in the spec.
- **REQ-005 Y/M approximation**: `isoDurationToSeconds` treats `P1Y` as 365 days and `P1M` as 30 days. This is good enough for human-facing worklog totals but not calendar-accurate. Specified as-is, flagged in Notes.

## Out of scope

- Refactoring any of the helpers — this PR is annotation-only.
- The remaining ActivityTimelineService private helpers not in the Bucket 2a cluster — they're either covered by existing REQs (per Bucket 1 annotation) or genuinely uninteresting (one-liners).
- The 5-REQ-per-run cap was not hit; the entire cluster is covered in one run.
