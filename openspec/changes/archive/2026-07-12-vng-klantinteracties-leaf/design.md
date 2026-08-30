## Context

Pipelinq stores CRM data in canonical English schema.org-aligned schemas:
`ticket` (the unified supertype with `ticketType` ∈ request/complaint/contactmoment),
`client` (`type` ∈ person/organization), `contact`, and `task` (`type` ∈
terugbelverzoek/opvolgtaak/informatievraag). Municipal KISS front-ends and other
OpenKlant-2 clients speak the Dutch VNG Klantinteracties API (OpenKlant 2.x, OAS
v0.8.0). OpenConnector's `vng-klantinteracties-adapter` change serves that API
over pipelinq's objects; this leaf supplies the pipelinq-side binding: the
canonical mapping contract, the AVG BSN policy, and the actor-registry bridge.
This mirrors the existing `80-zgw-api-bridge.json` fragment precedent — a pure
declarative fragment that references gateway machinery living elsewhere.

## Goals / Non-Goals

**Goals:**
- Freeze the VNG ↔ canonical mapping contract against real pipelinq field names.
- Mandate hash-only BSN handling (AVG) as an explicit, testable requirement.
- Add the minimal actor-registry bridge (`vngActor`) as config, plus seeds.

**Non-Goals:**
- The API surface, generic gateway features, and the Endpoints/Mappings/Rules
  themselves — those are the OpenConnector adapter change.
- Any new pipelinq PHP service, controller, or storage schema beyond `vngActor`.
- Klantinteracties v1 / zaken / klachten dialects.

## Decisions

### D1: Leaf is `kind: config` — a fragment, not code
The actor bridge is a schema, the mapping contract is documentation + declarative
Mappings (in OpenConnector), and the AVG policy is enforced gateway-side. Nothing
requires a pipelinq PHP class. Follows the `80-zgw-api-bridge.json` precedent.
_Alternative rejected:_ a `KlantinteractiesActorService` — an actor→UID lookup is
a plain object read (ObjectService), not a service.

### D2: Canonical storage is untouched; VNG is a mapping layer (ADR-001)
The mapping contract maps VNG fields onto EXISTING `ticket`/`client`/`contact`/`task`
fields; no VNG-shaped field is added to storage. This is the ADR-001 principle in
practice. _Alternative rejected:_ adding Dutch field aliases to the canonical
schemas — pollutes international storage and duplicates the mapping engine's job.

### D3: BSN is hash-only, reusing the BRP flow (AVG)
Inbound `partijIdentificator` BSNs are 11-proef-validated and SHA-256-hashed via
pipelinq's existing BRP flow (`brpPersoon.bsnHash`, `bsnValidatie`); the raw BSN
is never stored (`contact.verifiedBSN` is a boolean flag, `contact.brpPersoonId`
links to the hashed `brpPersoon`). Outbound, the leaf never reconstructs a raw
BSN. This deviates from VNG's raw-BSN expectation and is documented as a conscious
policy. _Alternative rejected:_ persisting raw BSN to satisfy VNG literally —
violates pipelinq's AVG posture and Dutch privacy law.

### D4: Actor bridge is a dedicated `vngActor` schema, not `agentProfile`
`agentProfile` models availability/skill-routing (isAvailable, maxConcurrent,
skills); the VNG actor bridge needs actor UUID ↔ NC userId identity mapping with
actor type. A dedicated small schema is clearer than overloading `agentProfile`.

## VNG ↔ Canonical Mapping Contract

Real pipelinq field names (verified against `lib/Settings/pipelinq_register.json`
and `register.d/99-unify-ticket-supertype.json`).

| VNG Klantinteracties | Canonical pipelinq | Notes |
|----------------------|--------------------|-------|
| `klantcontact` | `ticket` with `ticketType=contactmoment` | the contactmoment subtype of the unified ticket |
| `klantcontact.onderwerp` | `ticket.title` | |
| `klantcontact.tekst` | `ticket.description` | |
| `klantcontact.kanaal` | `ticket.channel` | telefoon/email/balie/chat/social/brief |
| `klantcontact.plaatsgevondenOp` | `ticket.occurredAt` | |
| `partij` | `client` | |
| `partij.soortPartij` | `client.type` | persoon→person, organisatie→organization |
| `partij.indicatieGeheimhouding` | `contact.geheimhouding` | address shielding flag |
| `partijIdentificator` (BSN) | `contact.verifiedBSN` (bool) + `contact.brpPersoonId` → `brpPersoon.bsnHash` | hash-only; never raw (D3) |
| `betrokkene` | `contact` | |
| `digitaalAdres` (soort=email) | `contact.email` | |
| `digitaalAdres` (soort=telefoon) | `contact.phone` | |
| `internetaak` | `task` | |
| `internetaak` (terugbelverzoek) | `task.type=terugbelverzoek` + `task.callbackPhoneNumber` | callback |
| `onderwerpobject` | `ticket.caseReference` / `ticket.parentTicket` | case/parent linkage |
| `actor` | Nextcloud UID assignee (`ticket.assignee` / `task.assigneeUserId`) | via `vngActor` bridge |

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|-----------|------|-----------|
| VNG ↔ canonical field mapping | **Config** (OpenConnector Mappings, referenced by slug) | Pure data-shape transform; the mapping engine handles it. No pipelinq code. |
| Actor-registry bridge | **Config** (`vngActor` schema + seeds) | Identity lookup is a plain OpenRegister object read; no service. |
| AVG BSN validate + hash | **Config contract, enforced gateway-side** | The hashing is pipelinq's existing BRP flow; the leaf mandates it as a requirement + declares no raw BSN is stored. The enforcement Rule lives in the OpenConnector adapter (external-integration exception, ADR-031). |
| Binding to OpenConnector slugs | **Config** (fragment references Endpoint/Mapping/Rule slugs) | Declarative wiring data, not behaviour. |

**What stays config:** everything. This leaf introduces no lifecycle, aggregation,
calculation, notification, or widget behaviour — it is a schema fragment
(`vngActor` + binding config) plus a documented mapping contract and an AVG policy
requirement. No `lib/Service/*Service.php` is added.

## Seed Data

Seed objects ship in the `82-vng-klantinteracties.json` fragment so the leaf is
testable on install. General organisation data (municipality context).

### Schema: `vngActor`
| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `actor-medewerker-kcc-annelies` | `actor-systeem-pipelinq` | `actor-eenheid-vth` |
| actorUuid | `00000000-0000-0000-0000-00000000ac01` | `00000000-0000-0000-0000-00000000ac02` | `00000000-0000-0000-0000-00000000ac03` |
| actorType | medewerker | geautomatiseerdeActor | organisatorischeEenheid |
| nextcloudUserId | `annelies` | `pipelinq` | `team-vth` |
| naam | Annelies de Wit (KCC) | Pipelinq systeemactor | Afdeling VTH |
| actief | true | true | true |

### Schema: `vngKlantinteractieBinding`
| Field | Object 1 |
|-------|----------|
| slug | `binding-default` |
| klantcontactenEndpoint | `vng-klantcontacten` (openconnector slug) |
| partijenEndpoint | `vng-partijen` |
| maakKlantcontactEndpoint | `vng-maak-klantcontact` |
| avgBsnPolicyRule | `vng-avg-bsn-policy` |
| referentienummerRule | `vng-referentienummer` |
| actief | true |

**Related items per object:** each `vngActor` seed references an existing/seeded
Nextcloud user id; the `vngKlantinteractieBinding` references the OpenConnector
adapter slugs verbatim (frozen in that change's design.md Seed Data).

## Risks / Trade-offs

- [Slug drift with the OpenConnector adapter] → the adapter change freezes the
  Endpoint/Mapping/Rule slugs; this fragment references them verbatim and the leaf
  is gated on the adapter landing first.
- [VNG conformance test expects a raw BSN] → the AVG hash-only deviation is
  documented explicitly in the spec so it is an intentional, reviewable choice.
- [Actor bridge incomplete for automated/organisational actors] → `actorType`
  enumerates medewerker / geautomatiseerdeActor / organisatorischeEenheid so all
  three VNG actor kinds map.
