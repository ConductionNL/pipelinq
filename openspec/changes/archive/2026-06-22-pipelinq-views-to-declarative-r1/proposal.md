# Proposal: Pipelinq views → declarative (round 1)

## Why

Pipelinq renders its shell from `src/manifest.json` via the
`@conduction/nextcloud-vue` manifest renderer. The library supports four
declarative page types — `index` (CnIndexPage), `detail` (CnDetailPage),
`dashboard` (CnDashboardPage) and the escape hatch `custom` (a host-app
registry component). Every `type:"custom"` page is bespoke Vue the app has to
maintain, test and keep visually consistent by hand.

A read-only audit classified pipelinq's list / dashboard `type:"custom"` pages
into two buckets:

- **A — convertible**: pure `CnIndexPage`/`CnDashboardPage` wrappers whose
  columns, row navigation and (now) per-value status badges + date formatting
  can be expressed entirely in the manifest using the library's declarative
  column renderers (`widget:"badge"` + `colorMap`, `format:"date"|"date-time"`,
  `type:"boolean"`) and `actions`/`headerActions` (`handler:"navigate"`).
- **C — not (yet) convertible**: pages that need a cell renderer the library
  does not yet expose declaratively (currency, colour swatch), a bespoke
  client-side sort, a create-to-detail action the declarative `navigate`
  handler cannot express (it always passes `{ id: row[rowKey] }`, so it cannot
  produce `{ id: 'new' }`), or a custom cross-module aggregation endpoint with a
  page-level filter that no declarative widget can drive.

This change is **round 1** of a program to drive the `type:"custom"` count
toward zero: delete confirmed-dead orphan views, convert the bucket-A pages, and
record bucket-C pages as kept-with-reason so a future nc-vue primitive can pick
them up.

## What Changes

- **Deletes** confirmed-dead orphan views (no importer anywhere in `src/`).
- **Converts** these list pages from `type:"custom"` to declarative
  `type:"index"`, removing the host view + its `registry` `kind:page` entry:
  `PosTransactions`, `PosRefunds`, `Blasts`, `ZReports`, `Bookings`.
- **Keeps custom (with a recorded reason + the missing nc-vue primitive)**:
  `Resources`, `Services`, `Projects`, `BillingCategories`, `Analytics`,
  `AvgIntake`.

No feature contract, data model, or API surface changes — the deltas below are
**view-rendering placement** only. Each converted page renders the same
register/schema, the same columns, and routes to the same detail/editor.

## Impact

- Affected specs: `declarative-view-system` (new capability).
- Affected code: `src/manifest.json`, `src/manifest.d/75-marketing-blasts.json`,
  `src/manifest.d/80-appointment-booking-admin.json`,
  `src/manifest.d/60-klantbeeld-360.json`, `src/registry.js`, and the deleted
  list views under `src/views/`.
