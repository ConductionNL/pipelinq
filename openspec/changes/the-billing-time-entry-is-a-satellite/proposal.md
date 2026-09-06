# The billing time entry is a satellite

## Why

Three apps declared a `timeEntry`. A schema slug is global per organisation and
`SchemaMapper::find()` matches `LOWER(slug)`, so whichever row was reached first
answered for all three.

| | shape | required |
| --- | --- | --- |
| humaniq `TimeEntry` | a clocked interval, or since humaniq#323 a day booking | `date` or clock times |
| planninq `timeEntry` | a duration booked to a project task | `task`, `user`, `duration`, `date` |
| this app's `timeEntry` | the BILLING record of time worked | `title`, `hours`, `date` |

humaniq is the agreed owner of the hours. This app's record is not a second copy
of them: it is client, lead, billing category, approval state, WIP sync status
and invoice batch — the billing side of a booking.

## What changes

`timeEntry` becomes `billingTimeEntry`, and gains a `timeEntry` property naming
the humaniq booking it bills. A plain uuid, not a `$ref`: humaniq's register is
a different register and ADR-062 rule 7 gives a cross-register target no `$ref`.

The app-config key `timeEntry_schema` does not move. It is live persisted state,
and the same split already applies to `klantLoyaltyAccount_schema` two lines
below it in the same map.

`RenameTimeEntrySchemaSlug` renames the row before the import, modelled on
`RenameLoyaltyAccountSchemaSlug` which exists for exactly this. Without it the
descriptor change renames nothing: OpenRegister's import creates a second schema
and orphans every existing billing line, silently.

## What this does NOT do yet

**The hours still live in both places.** This app's `hours`, `date`, `user` and
`description` stay on `billingTimeEntry` rather than moving to the humaniq
booking, because `BillingCategoryWidget` sums `hours` per `billingCategory` and
moving them would make that widget read across two apps.

The fleet already has the pattern for that — a `requiredApp` widget, which
gate-55 supports and which HIDES rather than rendering empty when the backing
app is absent; pipelinq itself reads planninq's `project` that way. Applying it
here is the next change, and it is what makes the hours live once.

The collision is cleared either way, which is what this change is for.
