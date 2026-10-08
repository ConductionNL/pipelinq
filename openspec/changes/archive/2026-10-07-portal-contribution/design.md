# Design: portal-contribution

## Context

hydra ADR-046 defines portaliq as the ONE shared external portal for people
without Nextcloud accounts. Contract v2 (2026-07-06 amendment): apps contribute
via a single plain class at convention FQCN, duck-typed by portaliq
(`method_exists()`, never `instanceof`). pipelinq already runs a bespoke portal
(`lib/Controller/Portal*`, `lib/Service/Portal/*`); this change ADDS the
declarative contribution alongside it — retirement of the bespoke portal is a
later phase on Conduction/pipelinq#343 and nothing in that tree is touched here.

All register facts below were verified against HEAD
(`lib/Settings/pipelinq_register.json` + `lib/Settings/register.d/*.json`,
branch point `origin/development` @ cb675a06).

## Claim-names contract (pipelinq's claim namespace — STABLE, do not rename)

`scopeClaim` with a bare name resolves against the server-managed portalAccount
claim bag `claims.pipelinq.<name>`. These three names are pipelinq's claim
contract with portaliq operators; they mirror the bespoke portal's
`portalAccount.linkedOrganisationId` / `linkedContactId` semantics 1:1 so the
eventual account migration is mechanical:

| Claim (claims.pipelinq.*) | Value (UUID domain ref) | Bespoke-portal equivalent | Used by audience |
|---|---|---|---|
| `clientId` | pipelinq `client` object UUID (the B2B organisation) | `portalAccount.linkedOrganisationId` | client |
| `contactId` | pipelinq `contact` object UUID (the person) | `portalAccount.linkedContactId` | customer (DSAR) |
| `customerUid` | Nextcloud addressbook contact UID (`contact.contactsUid` identity space, per the fleet "Contact is a Nextcloud entity" convention) | — (new) | customer (loyalty) |

`customerUid` is a separate claim because `klantLoyaltyAccount.klantId` stores
the *Nextcloud contact UID* ("Nextcloud contact UID (OCP\Contacts\IManager
link)"), NOT the pipelinq `contact` object UUID — two identifier spaces, two
claims. Conflating them would silently scope loyalty reads to nothing.

## Scoping map (schema → scopeField → claim), all register `pipelinq`

| Audience | Schema | scopeField | Property verified at HEAD | scopeClaim | minTrust |
|---|---|---|---|---|---|
| client | `request` | `client` | `type: string, format: uuid` (main register) | `clientId` | — |
| client | `complaint` | `client` | `type: string, format: uuid` (main register) | `clientId` | — |
| client | `contract` | `clientRef` | `type: string, format: uuid` (96-contract-renewal.json) | `clientId` | — |
| customer | `avgVerzoek` | `verzoekerContact` | "UUID reference to the linked Contact" (40-avg-verzoeken.json) | `contactId` | `substantial` |
| customer | `klantLoyaltyAccount` | `klantId` | "Nextcloud contact UID" (70-loyalty-program.json) | `customerUid` | — |

Create actions (whitelists — never lifecycle/assignment/verification fields):

| Audience | Action id | Schema | Fields |
|---|---|---|---|
| client | `createRequest` | `request` | `title`, `description`, `category` |
| client | `createComplaint` | `complaint` | `title`, `description`, `category` |
| customer | `createAvgVerzoek` | `avgVerzoek` | `artikel`, `specifiekeVraag`, `scope` |

`minTrust: substantial` on the DSAR collection: GDPR rights requests carry the
requester's own case file (incl. their BSN fields); eIDAS-substantial identity
assurance is the floor before portaliq may show rows. The actions vocabulary
carries no `minTrust` key in Wave 1, so intake assurance is portaliq's edge
policy — noted as a contract-v3 wish.

## Exclusions (judgment calls — Wave-1 collections expose WHOLE rows, no field projection)

- **`contactmoment` (client candidate) — EXCLUDED.** `notes` is "Additional
  internal notes" with `visible: false`, plus raw `channelMetadata` and agent
  identity. A full-row collection would hand agent notes to the client.
- **`booking` (customer candidate) — EXCLUDED.** `internalNotes` is documented
  in the schema itself as "Staff-only notes; never returned to the customer
  portal" — the bespoke portal enforces that projection in PHP; portaliq Wave-1
  collections cannot. Fail-closed until the contract gains collection field
  whitelists or a portal-safe booking projection schema exists (deferred on
  #343; also flagged as a fleet contract gap in the 2026-07-06 portaliq review).
- **`berichtenboxMessage` inbox — SKIPPED.** It is scoped by `bsn`/`bsnHash`
  (encrypted BSN + HMAC), not by a contact/customer UUID domain ref. BSN may
  never become a portal scoping claim, so no `kind: 'inbox'` collection ships
  in Wave 1. Rows also carry Logius delivery internals.
- **`avgVerzoek` READ is INCLUDED despite process fields** (`behandelaar`,
  `dpiaFlag`, `fgGeinformeerd`): every row is exclusively about the requester's
  own case (scoped `verzoekerContact`), no field is documented staff-only, and
  DSAR transparency to the data subject is the point of the surface. The BSN
  fields a requester may see are their own.
- Everything else in the register (POS, marketing, forecast, projects, portal
  bookkeeping schemas like `portalAccount`/`portalSession`) is out of scope by
  default — the manifest is an explicit allowlist.

## Declarative vs imperative

**Decision: fully declarative — pure-data manifest, zero I/O.** The provider
branches only on `$subject['audience']` (server-derived per ADR-005) and returns
constants. Rejected alternatives:

- *Imperative provider* (query OR to tailor collections per subject): portaliq
  already RBAC-scopes reads server-side; app-side queries would duplicate the
  authz path (ADR-022 violation), add OR coupling to a class whose whole value
  is being dependency-free, and turn a discovery probe into a data access.
- *Reusing the bespoke `PortalScopeResolver`/facades*: couples the contribution
  to code scheduled for retirement and would require constructor dependencies,
  breaking duck-typed discovery's inertness guarantee.

Consequence: anything requiring per-subject logic (delegations, tenant config)
stays in portaliq or in later waves; the manifest stays audit-readable data.

## Seed Data (unit-test fixtures — nil-pattern UUIDs only)

Tests construct the provider directly (no container) and feed synthetic
subjects built on the nil-UUID pattern so fixtures can never collide with live
data and are self-evidently fake:

```php
$clientSubject = [
    'subjectRef'   => '00000000-0000-0000-0000-000000000001',
    'audience'     => 'client',
    'organisation' => '00000000-0000-0000-0000-000000000002',
    'trust'        => 'substantial',
];
$customerSubject = [
    'subjectRef'   => '00000000-0000-0000-0000-000000000003',
    'audience'     => 'customer',
    'organisation' => '00000000-0000-0000-0000-000000000002',
    'trust'        => 'substantial',
];
```

No OR seed objects are needed: the provider performs no I/O, so no registers,
schemas, or objects are created by this change. Live-portal seeding (a
portalAccount with `claims.pipelinq.clientId` etc.) belongs to portaliq's own
e2e environment, keyed by the claim-names contract above.

## Risks

- Claim names are load-bearing the moment a portaliq operator provisions
  accounts — hence the STABLE marker above; renames are a breaking change.
- If a future register edit adds a staff-only field to an included schema, the
  full-row exposure re-opens. Mitigation: the exclusion rationale above is the
  review checklist for register PRs touching `request`, `complaint`,
  `contract`, `avgVerzoek`, `klantLoyaltyAccount`.
