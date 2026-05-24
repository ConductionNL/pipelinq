---
retrofit_extensions:
  - REQ-001
  - REQ-002
  - REQ-003
  - REQ-004
  - REQ-005
---

## Requirements

### REQ-001: Activity-type filter MUST normalise to a closed allow-list

The timeline query MUST accept `types` as either an array, a comma-separated string, or null/empty. Unknown or empty values MUST be dropped silently. When the resulting set is empty (no input or no recognised values), the query MUST treat the filter as absent rather than as "match nothing". The allow-list is exactly: `contactmoment`, `task`, `email`, `calendar`, `worklog`.

#### Scenario: Comma-separated string is split and lower-cased
- GIVEN the caller passes `types = "Task, Email, unknown"`
- WHEN `getTimeline()` runs
- THEN the effective filter MUST be `["task", "email"]`
- AND the unrecognised "unknown" value MUST be silently dropped

#### Scenario: Empty input disables the filter
- GIVEN the caller passes `types = ""` (or `null`, or `[]`)
- WHEN `getTimeline()` runs
- THEN no type filter MUST be applied (returns `null` internally)

#### Scenario: All-unknown input disables the filter
- GIVEN the caller passes `types = "foo,bar"` (no values match the allow-list)
- WHEN `getTimeline()` runs
- THEN no type filter MUST be applied
- AND results from every allow-listed type MUST still be returned

**Notes**
- Observed: `types="foo,bar"` falls back to "no filter" rather than "no results". This is observed behavior — review separately whether silent fall-through is the right default for callers expecting strict matching.

---

### REQ-002: Internal source-types MUST map to public activity-type labels

Per-schema query keys (`contactmoment`, `task`, `emailLink`, `calendarLink`) MUST be projected onto the public activity-type labels exposed in API responses. The mapping is exact and one-way; unknown source-types pass through unchanged.

#### Scenario: emailLink maps to email
- GIVEN an internal source-type key `emailLink`
- WHEN it is translated for the public response
- THEN the activity type MUST be `email`

#### Scenario: calendarLink maps to calendar
- GIVEN an internal source-type key `calendarLink`
- WHEN it is translated for the public response
- THEN the activity type MUST be `calendar`

#### Scenario: contactmoment passes through
- GIVEN an internal source-type key `contactmoment`
- WHEN it is translated
- THEN the activity type MUST remain `contactmoment`

#### Scenario: Unknown source-type passes through
- GIVEN an internal source-type key `worklog` (not explicitly mapped)
- WHEN it is translated
- THEN the activity type MUST remain `worklog`

---

### REQ-003: Schema queries MUST tolerate OpenRegister result-shape variance

A single per-schema query MUST issue a `findAll()` against OpenRegister with `_rbac: false` and `_multitenancy: false`, capped at the per-schema ceiling. The result MUST be normalised to a flat array of plain `array<string,mixed>` payloads regardless of whether OpenRegister returns plain arrays, Entity objects with `getObject()`, or anything else iterable. When an Entity has `getObject()` but no `id`, the entity's `getUuid()` MUST be promoted into the payload as `id`. Any thrown `\Throwable` MUST be logged and the query MUST return an empty array (never propagate the exception to the caller).

#### Scenario: findAll returns Entity objects with getObject()
- GIVEN OpenRegister `findAll()` returns an array of Entity objects each exposing `getObject(): array` and `getUuid(): string`
- WHEN `querySchema()` processes the result
- THEN each Entity's `getObject()` payload MUST be returned as a plain array
- AND when the payload lacks an `id` key, the entity UUID MUST be injected as `id`

#### Scenario: findAll returns mixed plain-array entries
- GIVEN OpenRegister `findAll()` returns a mix of plain arrays and Entity objects
- WHEN `querySchema()` processes the result
- THEN plain arrays MUST be returned as-is
- AND Entity entries MUST be unwrapped per the Entity rule above

#### Scenario: findAll throws
- GIVEN OpenRegister `findAll()` throws an exception
- WHEN `querySchema()` catches it
- THEN the exception MUST be logged at `error` level with the schema ID and exception message
- AND `querySchema()` MUST return `[]`
- AND the broader `getTimeline()` request MUST NOT fail because one schema query failed

#### Scenario: saveObject return shape normalisation
- GIVEN a save path receives back an Entity, a plain array, or an arbitrary object
- WHEN the value is run through the extraction helper
- THEN a plain array MUST be returned in all three cases
- AND for Entity inputs, the same `id`-from-`getUuid()` rule MUST apply

---

### REQ-004: Date-range filtering MUST be inclusive with end-of-day handling

Timeline items MUST be filterable by `from` and `to` ISO dates. Both bounds are inclusive. A bare-date `to` value (length 10, `YYYY-MM-DD`) MUST be treated as `T23:59:59` on that date. Items with a null/empty date MUST be excluded when any bound is given. Items whose date string fails to parse MUST be excluded.

#### Scenario: No bounds — all items pass
- GIVEN `from = null` and `to = null`
- WHEN any item is evaluated
- THEN it MUST pass the filter regardless of its date

#### Scenario: Bare to-date is inclusive to end-of-day
- GIVEN `to = "2026-05-24"` and an item dated `2026-05-24T18:00:00Z`
- WHEN the item is evaluated
- THEN it MUST pass the filter

#### Scenario: from is inclusive
- GIVEN `from = "2026-05-01T00:00:00Z"` and an item dated `2026-05-01T00:00:00Z`
- WHEN the item is evaluated
- THEN it MUST pass the filter

#### Scenario: Null date excluded when bounds present
- GIVEN any non-null `from` or `to` is supplied
- AND an item has `date = null`
- WHEN the item is evaluated
- THEN it MUST be excluded

#### Scenario: Unparseable date excluded
- GIVEN an item has `date = "not-a-date"` and any bound is set
- WHEN the item is evaluated
- THEN it MUST be excluded (no crash)

---

### REQ-005: Worklog totals MUST round-trip via ISO 8601 duration

Worklog entries supply per-item durations as ISO 8601 strings (the `PT[H][M][S]` and `P[D]T[H][M][S]` subsets). Totals MUST be computed in seconds and re-emitted as ISO 8601 in the response. Unparseable or empty input durations MUST contribute zero seconds (never throw). Year/month components MUST be approximated as 365 days / 30 days respectively. A zero total MUST be formatted as `PT0S`. Non-zero totals MUST omit zero-valued H/M/S components, but a non-zero seconds-only total MUST still emit the `S` component.

#### Scenario: PT2H30M parses to 9000 seconds
- GIVEN duration string `PT2H30M`
- WHEN parsed
- THEN the result MUST be `9000`

#### Scenario: Empty string parses to 0
- GIVEN duration string `""`
- WHEN parsed
- THEN the result MUST be `0`

#### Scenario: Garbage input parses to 0
- GIVEN duration string `not-a-duration`
- WHEN parsed
- THEN the result MUST be `0` (no exception propagates)

#### Scenario: 9000 seconds formats as PT2H30M
- GIVEN seconds total `9000`
- WHEN formatted
- THEN the result MUST be `PT2H30M`

#### Scenario: Zero seconds formats as PT0S
- GIVEN seconds total `0` (or negative)
- WHEN formatted
- THEN the result MUST be `PT0S`

#### Scenario: 45 seconds formats as PT45S
- GIVEN seconds total `45`
- WHEN formatted
- THEN the result MUST be `PT45S` (the `S` component is preserved even though hours/minutes are zero)

**Notes**
- Y/M approximations (365d/30d) are observed behavior; this is acceptable for human-facing totals but is not calendar-correct. Flag for future refinement if worklogs ever need calendar-accurate aggregation across month/year boundaries.
