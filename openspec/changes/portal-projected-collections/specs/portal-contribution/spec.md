# Portal Contribution — Field-Projected Collections Delta

**Spec refs**: hydra ADR-046 (portaliq external portal) + read-side field-projection amendment (2026-07-06 — a collection may declare `fields`; portaliq whitelist-projects rows after per-row verification, identifiers always survive, a malformed declaration degrades to identifiers-only), ADR-005 (server-derived subject), ADR-022 (apps consume OR abstractions)
**Standards**: eIDAS assurance vocabulary for `minTrust` (`low` | `substantial` | `high`); GDPR Art. 9 (special-category data) motivates the booking trust floor

## ADDED Requirements

### Requirement: Field-Projected Client Contactmoment Collection

For a subject with `audience = 'client'`, `getContribution()` MUST return a manifest whose collections include `contactmoment` from the `pipelinq` register, scoped by `client` via `scopeClaim: "clientId"`, with a `fields` whitelist of exactly `subject`, `channel`, `outcome`, `contactedAt`. The whitelist MUST NOT include any staff-only or internal property — `summary`, `notes`, `channelMetadata`, `duration`, `agent`, `contactsUid`, the linking refs, or any CTI union field (recording URL, disposition notes, telephony numbers).

#### Scenario: Client contactmoment is field-projected to client-safe facts

- **GIVEN** a resolved subject with `audience = 'client'`
- **WHEN** `getContribution($subject)` is called
- **THEN** the manifest MUST contain a `contactmoment` collection (id `clientContactmoments`, scopeField `client`, `scopeClaim` `clientId`, register `pipelinq`, `listable: true`) with `fields` exactly `["subject", "channel", "outcome", "contactedAt"]`
- **AND** the `fields` whitelist MUST NOT contain `notes`, `channelMetadata`, `duration`, `agent`, `contactsUid`, `summary`, `recording_url`, or `disposition_notes`
- `@e2e exclude` projection is enforced portaliq-side after per-row verification; pipelinq ships only the declarative whitelist, pinned by PHPUnit — no pipelinq UI renders it

### Requirement: Field-Projected Customer Booking Collection

For a subject with `audience = 'customer'`, `getContribution()` MUST return a manifest whose collections include `booking` from the `pipelinq` register, scoped by `customerId` via `scopeClaim: "customerUid"` (the Nextcloud addressbook contact-UID identity space, NOT the pipelinq `contact` object UUID), gated at `minTrust: "substantial"`, with a `fields` whitelist of exactly `serviceId`, `startAt`, `endAt`, `status`, `notes`, `depositAmount`, `depositPaidAt`. The whitelist MUST NOT include `internalNotes`, `statusHistory`, `resourceAssignments`, cancellation-actor fields, or provenance/ops timestamps.

#### Scenario: Customer booking is field-projected and trust-gated

- **GIVEN** a resolved subject with `audience = 'customer'`
- **WHEN** `getContribution($subject)` is called
- **THEN** the manifest MUST contain a `booking` collection (id `customerBookings`, scopeField `customerId`, `scopeClaim` `customerUid`, `minTrust` `substantial`, register `pipelinq`, `listable: true`) with `fields` exactly `["serviceId", "startAt", "endAt", "status", "notes", "depositAmount", "depositPaidAt"]`
- **AND** the `fields` whitelist MUST NOT contain `internalNotes`, `statusHistory`, `resourceAssignments`, `source`, `cancelledBy`, `cancellationReason`, or `previousBookingId`
- `@e2e exclude` booking projection + trust gate are declarative data enforced portaliq-side; pinned by PHPUnit, no pipelinq CI UI

### Requirement: Projection Whitelists Track The Shipped Register

Every field declared in a collection `fields` whitelist MUST exist as a property on the collection's schema in the shipped register config (`pipelinq_register.json` + `register.d/*.json`, union-merged), so a register drift (renamed or removed field) fails a pin test instead of silently dropping a projected column. `berichtenboxMessage` MUST remain excluded from all audiences (BSN-scoped, not a contact/customer UUID domain ref).

#### Scenario: Projected fields exist on the schema; Berichtenbox stays excluded

- **WHEN** the register-drift pin test loads the schema property universe at HEAD
- **THEN** every `fields` entry on every collection MUST resolve to a real property on that collection's schema
- **AND** no manifest for any audience may contain a `berichtenboxMessage` collection
- `@e2e exclude` register-drift is a static config invariant asserted by PHPUnit against the shipped JSON; no runtime UI
