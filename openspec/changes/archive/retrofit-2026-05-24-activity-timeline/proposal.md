# Retrofit — activity-timeline

Describes observed behavior of 8 private helper methods on `ActivityTimelineService` as 5 new REQs. The code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/ActivityTimelineService.php::sourceToActivityType()`
- `lib/Service/ActivityTimelineService.php::normaliseTypes()`
- `lib/Service/ActivityTimelineService.php::querySchema()`
- `lib/Service/ActivityTimelineService.php::normaliseResultset()`
- `lib/Service/ActivityTimelineService.php::withinDateRange()`
- `lib/Service/ActivityTimelineService.php::extractObjectArray()`
- `lib/Service/ActivityTimelineService.php::isoDurationToSeconds()`
- `lib/Service/ActivityTimelineService.php::secondsToIsoDuration()`

## Approach

- For each helper: read the implementation, extract inputs / outputs / pre/postconditions / failure modes
- Cluster helpers with shared observable behavior into a single REQ (e.g. `querySchema` + `normaliseResultset` + `extractObjectArray` together describe "tolerate OR result-shape variance")
- REQ language matches observed behavior — bugs stay bugs, surfaced in Notes
- Keep the REQ count to 5 to honor the per-run cap

Source: `openspec/coverage-report.md` generated 2026-05-24. See the [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
