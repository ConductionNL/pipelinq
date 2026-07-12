---
kind: config
depends_on: []
---

## Why

VNG Klantinteracties / OpenKlant 2.x interop is, per Specter research 2026-07-12,
"the biggest strategic gap" for the CRM/KCC line — a procurement gate that
KISS-based municipal tenders make mandatory. Pipelinq already stores contact
moments, clients, contacts and tasks in canonical English schema.org schemas;
what is missing is the binding that lets OpenConnector serve those objects as the
Dutch Klantinteracties API. This change supplies that binding as **configuration**,
holding to the pipelinq architecture principle (ADR-001,
`docs/Technical/architecture.md`): **"Data storage uses international standards.
Dutch government standards are an API mapping layer."** The API surface itself and
its generic gateway mechanics live in the OpenConnector change
`vng-klantinteracties-adapter`; this is the pipelinq-side leaf that documents the
canonical mapping contract, mandates the AVG BSN policy, and adds the small
actor-registry bridge the mapping needs.

## What Changes

- Add a new `lib/Settings/register.d/82-vng-klantinteracties.json` fragment
  (declarative, no PHP) that (a) references the OpenConnector Endpoint / Mapping /
  Rule slugs defined in `vng-klantinteracties-adapter`, and (b) adds the
  actor-registry bridge schema.
- Document the **VNG ↔ canonical mapping contract** as the spec's core, using
  pipelinq's REAL field names: `klantcontact` ↔ `ticket` (ticketType=contactmoment),
  `partij` ↔ `client`, `betrokkene` ↔ `contact`, `digitaalAdres` ↔ `contact.email`
  / `contact.phone`, `internetaak` ↔ `task`, `onderwerpobject` ↔
  `ticket.caseReference` / `parentTicket`, `actor` ↔ Nextcloud UID assignee,
  `partij.indicatieGeheimhouding` ↔ `contact.geheimhouding`.
- Add the **AVG BSN policy requirement**: inbound `partijIdentificator` BSNs are
  validated (11-proef) and SHA-256-hashed via pipelinq's existing BRP flow
  (`brpPersoon.bsnHash`); the leaf NEVER stores or reconstructs a raw BSN. A
  documented deviation from VNG's raw-BSN expectation.
- Add a small **actor-registry bridge** schema (`vngActor`) mapping a VNG actor
  UUID to a Nextcloud `userId` so `actor` ↔ assignee resolves; config only (a
  schema + seeds), no service class.
- Add seed objects for the actor bridge and the binding config so the leaf is
  testable on install.

## Capabilities

### New Capabilities
- `vng-klantinteracties-leaf`: the pipelinq-side VNG Klantinteracties binding — the
  VNG ↔ canonical mapping contract, the AVG BSN hash-only policy, the
  actor-registry bridge schema, and the `register.d` fragment + seeds that
  reference the OpenConnector Endpoint / Mapping / Rule slugs.

### Modified Capabilities
<!-- No existing capability's REQUIREMENTS change. omnichannel-registratie,
     contactmomenten and brp-lookup are referenced (their VNG-mapping and BSN-hash
     claims are realised by this leaf) via an OpenSpec-changes note on each spec,
     but no requirement text changes, so none is listed here as a delta. -->
- _(none)_

## Impact

- **New config**: `lib/Settings/register.d/82-vng-klantinteracties.json` (fragment
  + `vngActor` schema + binding config + seeds).
- **Referenced specs** (changes-list note, no requirement change):
  `omnichannel-registratie` and `contactmomenten` (this leaf realises their VNG
  Klantinteracties `Contactmoment`/`Kanaal`/`Partij`/`Betrokkene` mapping claims);
  `brp-lookup` (the AVG requirement consumes its 11-proef + `bsnHash` flow).
- **Cross-repo dependency**: OpenConnector change `vng-klantinteracties-adapter`
  MUST land first (it owns the Endpoints/Mappings/Rules/Consumers and the generic
  gateway features). Because `depends_on` is single-repo only, the dependency is
  documented here and in the adapter proposal, not in frontmatter. Do NOT apply
  this leaf until the adapter's slugs are stable.
- **No new PHP, no new controllers, no new services** — pure declarative config.
