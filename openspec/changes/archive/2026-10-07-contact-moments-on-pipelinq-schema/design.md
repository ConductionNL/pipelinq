# Design: contact-moments-on-pipelinq-schema

Kind: code. One property, one widened reference, two leaves, one
migration.

## D1. `direction` on the contact moment facet

In a new fragment `lib/Settings/register.d/98-contactmoment-direction.json`
(ordered before `99-unify-ticket-supertype.json` merges): `direction`,
string, enum `inbound`, `outbound`, `internal`, `facetable: true`,
required when `ticketType = contactmoment`, in the same guarded shape the
supertype uses for its per-type requireds.

Migration `Repair\MigrateContactMomentDirection`: for every contact moment
ticket without `direction`, read `channelMetadata.direction` or
`channelMetadata.richting`, map `inkomend|inbound` to `inbound`,
`uitgaand|outbound` to `outbound`, else `internal`; write the property;
leave `channelMetadata` untouched. Idempotent.

## D2. `caseReference`, widened

`caseReference` becomes an ADR-048 semantic reference: `x-semantic-type:
https://schema.conduction.nl/types/case`, resolved by whatever app
supplies that type. The description drops "Procest case ... (request
only)" and `x-external-register: procest`. Allowed on `request` and
`contactmoment` facets. Existing values (UUIDs) stay valid; the resolver
finds them through the semantic type's provider.

## D3. `pipelinq-contact-moments`, kind data-provider

- `lib/Integration/ContactMomentLeafProvider.php`, storage strategy
  `app-local`, registered on `RegisterLeafProvidersEvent` behind
  `class_exists()`.
- `list(register, schema, objectId)`: contact moment tickets with
  `caseReference = objectId`, newest first, with `subject`, `channel`,
  `direction`, `agent`, `occurredAt`, `outcome`, `summary`.
- `create(...)`: appends one contact moment ticket through `TicketService`
  (which stamps `ticketType`) with `caseReference = objectId`, `agent` =
  caller, `occurredAt` = now unless given, `direction` required in the
  payload. Refuses a caller without read on the host object.

## D4. `pipelinq-contact-moments-panel`, kind render-surface

`widget` and `tab` under one id (gate-24). The tab is the contactmomenten
list filtered to the host with the Channel and Direction facets; the
widget is the last five plus the quick-log form (`contactmomenten`,
Quick-Log Form requirement) with `direction` as a required radio. Both
read and write through the data-provider leaf.

## D5. dossiq's migration

Not pipelinq's code, described so the seam is clear: dossiq's repair step
reads each `customerContact` and each `kcc-werkplek` `contactmoment`, maps
`channel` and `direction`, and calls `create` on the leaf with the case as
host. When every row is migrated the two fragments are deleted.

## Risks

- A `channelMetadata.direction` value outside the two known spellings.
  Mapped to `internal` and logged with the ticket id, never dropped.
- Two apps supplying the `case` semantic type at once. ADR-048's resolver
  order decides; pipelinq stores the UUID and does not care.
