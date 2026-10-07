---
kind: code
depends_on: [unify-ticket-supertype]
---

# Proposal: contact-moments-on-pipelinq-schema

Competitor gap register, row 6.2 "Contact moments with channel and
direction" (`procest/_gaps/gap-register.md` in
ConductionNL/market-intelligence, 2026-09-13). Rated partial, owner
pipelinq, size M. Opened by the small-owner lane of the OpenSpec phase.

## Summary

One contact moment schema in the fleet: pipelinq's. A case app logs and
shows contact moments on its case through a pipelinq leaf, with the
channel and the direction as fields, instead of carrying a copy of the
schema. dossiq's two copies (`customerContact`, the `kcc-werkplek`
`contactmoment`) retire.

## Why

A schema slug is global per organisation and `SchemaMapper::find()`
matches `LOWER(slug)`. dossiq declares `contactmoment` in
`register.d/40-kcc-werkplek.json` and `customerContact` in
`register.d/30-kcc.json`; pipelinq declares `contactmoment` as the
`ticketType: contactmoment` facet of its `ticket` supertype
(`lib/Settings/register.d/99-unify-ticket-supertype.json`). Two schemas
under one slug is the collision the fleet audit of 2026-09-05 found
eighteen times, and the one `the-payroll-employee-is-a-facet` in shillinq
was written to end (the TimeEntry lesson, ownership rules). The register
follows the app that declared first: pipelinq. dossiq's archived
`contact-moments` (09-10) built its Communication tab over dossiq's own
schema, so the row stays open.

The best competitor in the register: xxllnc Zaken,
`frontend-mono/packages/communication-module/src/Communication.constants.ts`
(`_round2/compare/M1-functionality.md`). It carries channel and direction
as first-class fields. pipelinq's `ticket` carries `channel`; direction
lives inside `channelMetadata` as `richting` (contactmomenten spec) or
`direction` (omnichannel-registratie), two spellings for one fact.

## Affected projects

- `pipelinq`: the `ticket` facet for contact moments, one leaf, one
  migration of `channelMetadata.direction`.
- `dossiq`: places the leaf; retires two schemas. Not changed here.
- `integriq`: none. `kcc-cti-adapter` and the CTI overlay's own
  `direction` field on `70-cti.json` are unchanged and referenced.

## What changes

- `direction` as a first-class property on the contact moment facet, enum
  `inbound`, `outbound`, `internal`, required for `ticketType =
  contactmoment`, migrated from `channelMetadata.direction` and
  `channelMetadata.richting` (`inkomend` to `inbound`, `uitgaand` to
  `outbound`).
- `caseReference` on `ticket` widened: today "UUID of the Procest case this
  request converted into (request only)" with `x-external-register:
  procest`. It becomes a semantic reference (ADR-048) to any object of
  kind case, allowed on a contact moment, and the stale app id `procest`
  leaves the schema description; the semantic type replaces it.
- A data-provider leaf `pipelinq-contact-moments` (ADR-066): for a host
  object it lists the contact moments whose `caseReference` is that
  object, and its `create` appends one with the host as `caseReference`
  and the calling user as `agent`. A render-surface leaf
  `pipelinq-contact-moments-panel` (`widget` and `tab`) renders them with
  the quick-log form the `contactmomenten` spec already defines.
- The `contactmomenten` list view gains a Direction facet beside Channel.

## How dossiq consumes it

The register's dossiq half: "the Communication tab over pipelinq's
contactmoment with the case as domain object; retire customerContact and
the kcc-werkplek copy". dossiq places `pipelinq-contact-moments-panel` as
its Communication tab, migrates its rows into pipelinq's schema through
the leaf's `create` in a repair step, and drops the two fragments. One
task in dossiq's umbrella `competitor-parity-2026-09`, against the
archived `contact-moments`.

## ADRs

- ADR-048: the case is a semantic reference, so pipelinq names no app.
- ADR-066: two leaves, read and append only, no verb into dossiq.
- ADR-022: dossiq consumes the schema instead of copying it.
- ADR-037: the change lands as a register fragment.
- ADR-031: the notification rules of
  `pipelinq-open-client-on-contactmoment` keep working on the same schema.

## Existing specs it extends

`contactmomenten` (the entity and the quick-log form) and
`unify-ticket-supertype` (the facet).

## Out of scope

- The KCC panel (row 6.13, `kcc-agent-panel`) and the CTI seam.
- Contact moments on objects other than a case. `caseReference` is the
  seam this row asks for; a generic `subject` is a later widening.
