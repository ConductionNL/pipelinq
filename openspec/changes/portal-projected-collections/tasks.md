# Tasks: portal-projected-collections

Tracking issue: Conduction/pipelinq#343 (Wave 2 — lifts the two Wave-1 field-projection exclusions; bespoke-portal retirement is still a later phase, do not close the issue).

- [x] T1: Verify at HEAD that `contactmoment.client` (uuid ref → `clientId`) and `booking.customerId` (Nextcloud addressbook contact ref → `customerUid`, NOT `contactId`) exist and enumerate every staff-only/internal field on both schemas (incl. the CTI union on contactmoment).
  - Success: scopeField + claim decisions quoted in design.md; whitelist tables list every excluded field with a schema-grounded reason.

- [x] T2: Add the `clientContactmoments` collection to `clientContribution()` — scopeField `client`, scopeClaim `clientId`, `fields` = subject/channel/outcome/contactedAt.
  - Success: no staff-only/internal/CTI field appears in the whitelist.

- [x] T3: Add the `customerBookings` collection to `customerContribution()` — scopeField `customerId`, scopeClaim `customerUid`, `minTrust` `substantial`, `fields` = serviceId/startAt/endAt/status/notes/depositAmount/depositPaidAt.
  - Success: `internalNotes`, `statusHistory`, `resourceAssignments`, cancellation-actor and provenance fields are absent from the whitelist.

- [x] T4: Update the class + method docblocks that documented the exclusions so they describe the field-projected inclusion (and that berichtenboxMessage stays excluded).
  - Success: no docblock still claims contactmoment/booking are "deliberately absent".

- [x] T5: Update the collection-set + register-drift pin tests for the new entries, and extend the drift test so every projected collection `fields` entry must exist on the schema.
  - Success: `testManifestMatchesShippedRegisterSchemas` covers collection `fields`, not just scopeField + action fields.

- [x] T6: Add unit tests pinning the exact projection whitelists and asserting every staff-only/internal field is absent from each declaration.
  - Success: `testClientContactmomentIsFieldProjected` + `testCustomerBookingIsFieldProjected` pass.

- [x] T7: Run the gate suite the CI way (docker php:8.3-cli): `composer lint`, `phpcs`, `phpmd`, `psalm`, `phpstan`, and the unit suite (`vendor/bin/phpunit -c phpunit-unit.xml`); fix violations in touched files (max 3 cycles, report honestly).
  - Success: full unit suite green (no pre-existing test broken); mechanical gates clean on touched files.

- [x] T8: `openspec change validate portal-projected-collections --strict` green; tick tasks; commit on `feat/portal-projected-collections` (conventional message, no Co-Authored-By); do not push, do not open a PR.
