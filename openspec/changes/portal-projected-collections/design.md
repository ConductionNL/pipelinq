# Design: portal-projected-collections

## Context

Wave 1 (`portal-contribution`, merged) contributed pipelinq's client/customer read
collections + create actions to portaliq as WHOLE rows. Two candidate collections
were **fail-closed excluded** in that change's design.md because portaliq had no
field projection and both schemas carry staff-only properties:

> **`contactmoment` (client candidate) — EXCLUDED.** `notes` is "Additional internal
> notes" with `visible: false`, plus raw `channelMetadata` and agent identity. A
> full-row collection would hand agent notes to the client.
>
> **`booking` (customer candidate) — EXCLUDED.** `internalNotes` is documented in the
> schema itself as "Staff-only notes; never returned to the customer portal" ... Fail-
> closed until the contract gains collection field whitelists or a portal-safe booking
> projection schema exists.

portaliq's read-side field projection (merged 2026-07-06) is exactly that primitive:
a collection declares `fields: [...]`; portaliq projects each row to the whitelist
**after** its per-row scope check; identifiers always survive; a malformed `fields`
declaration degrades to identifiers-only. This change lifts both exclusions with
minimal whitelists. `berichtenboxMessage` stays out (BSN scoping — permanent).

All register facts below were verified against HEAD at branch point
`origin/development` @ 2b952792 (`lib/Settings/pipelinq_register.json` +
`lib/Settings/register.d/*.json`).

## Claim + scopeField decisions (verified, not guessed)

| Audience | Schema | scopeField | Property verified at HEAD (quote) | scopeClaim | minTrust |
|---|---|---|---|---|---|
| client | `contactmoment` | `client` | "UUID reference to the associated client (schema:recipient / KlantContactmoment)", `format: uuid` (pipelinq_register.json) | `clientId` | — |
| customer | `booking` | `customerId` | "UUID of the contact (Nextcloud addressbook entity) the booking is for." (register.d/45-appointment-booking.json) | `customerUid` | `substantial` |

- **`contactmoment` → `clientId`.** `contactmoment.client` is the pipelinq `client`
  object UUID — the SAME identifier `request.client`/`complaint.client` already use
  with the `clientId` claim in Wave 1. Consistent; no new claim needed.
- **`booking` → `customerUid`, NOT `contactId`.** The scopeField is `customerId`,
  described as "UUID of the contact (**Nextcloud addressbook entity**)". That is the
  Nextcloud addressbook contact-UID identity space — the SAME space
  `klantLoyaltyAccount.klantId` ("Nextcloud contact UID") uses with the `customerUid`
  claim. It is NOT the pipelinq `contact` object UUID that `avgVerzoek.verzoekerContact`
  ("UUID reference to the linked Contact") uses with `contactId`. Using `contactId`
  here would scope booking reads to nothing. Verified against the merged provider's
  claim-names contract (`portal-contribution/design.md`): `customerUid` = "Nextcloud
  addressbook contact UID", `contactId` = "pipelinq `contact` object UUID".
- **`minTrust: substantial` on `booking`.** The customer-facing `notes` field is
  documented "Customer-facing booking notes (e.g. **allergies**, vehicle plate)" —
  allergy data is GDPR Art. 9 special category. Gating the surface at eIDAS-substantial
  identity assurance mirrors how Wave 1 gated `avgVerzoek` (BSN case files). Loyalty
  stays ungated (points balance, no special category); `contactmoment` stays ungated
  (B2B interaction facts, no special category in the whitelist).

## Field whitelist — `contactmoment` (client audience)

| Field | Included? | Why (schema-grounded) |
|---|---|---|
| `subject` | ✅ | Required. schema:about — the topic of the interaction the client was party to. |
| `channel` | ✅ | Enum telefoon/email/balie/chat/social/brief — how the client was contacted. |
| `outcome` | ✅ | Enum afgehandeld/opgelost/… — the result of the client's own interaction. |
| `contactedAt` | ✅ | date-time — when the interaction happened. |
| `summary` | ❌ | "Summary **or notes** of the interaction" — free text not provably client-safe; a dedicated visible field, but its "or notes" wording can carry agent phrasing. Excluded pending product sign-off. |
| `notes` | ❌ | "Additional internal notes", `visible: false` — internal. |
| `channelMetadata` | ❌ | Raw channel metadata, `visible: false` — internal. |
| `duration` | ❌ | `visible: false` — internal. |
| `agent` | ❌ | "Nextcloud user UID of the agent who handled the interaction" — staff identity. |
| `client` / `request` | ❌ | UUID scope/link refs; `client` is the scope key (survives as identifier), `request` is an internal linking id, not a display field. |
| `contactsUid` | ❌ | (register.d/15) vCard foreign key — internal FK, not display. |
| CTI union fields | ❌ | (register.d/70-cti.json) `telephony_platform`, `external_call_id`, `direction`, `from_number`, `to_number`, `started_at`, `answered_at`, `ended_at`, `duration_seconds`, `queue_name`, `agent_skill`, `disposition_subject`, `disposition_outcome`, `disposition_notes`, `recording_url`, `recording_retention_expires_at`, `cti_extension` — telephony internals incl. the call recording URL and agent disposition notes. |

**Shipped whitelist:** `["subject", "channel", "outcome", "contactedAt"]`.

## Field whitelist — `booking` (customer audience)

| Field | Included? | Why (schema-grounded) |
|---|---|---|
| `serviceId` | ✅ | "UUID of the Service being booked" — what the customer booked (own data). |
| `startAt` | ✅ | Overall booking start — the customer's appointment time. |
| `endAt` | ✅ | Overall booking end — the customer's appointment time. |
| `status` | ✅ | Lifecycle status (confirmed/completed/cancelled-…) — the customer's booking state. |
| `notes` | ✅ | "**Customer-facing** booking notes (e.g. allergies, vehicle plate)" — customer-safe by the schema's own intent (drives `minTrust: substantial`). |
| `depositAmount` | ✅ | "Deposit amount captured at booking time" — the customer's own money. |
| `depositPaidAt` | ✅ | "Timestamp the deposit cleared" — the customer's own payment confirmation. |
| `internalNotes` | ❌ | Schema says "**Staff-only** notes; never returned to the customer portal". |
| `statusHistory` | ❌ | Append-only audit trail with `changedBy` (staff/system id) + `reason` — internal audit. |
| `resourceAssignments` | ❌ | Per-step `resourceId` UUIDs — internal resource/staff allocation. |
| `source` | ❌ | portal/admin/phone/walk-in/import — internal provenance. |
| `confirmationSentAt` / `reminderSentAt` | ❌ | Internal email-ops send timestamps. |
| `noShowFeeChargedAt` | ❌ | Penalty-processing internal timestamp. |
| `cancellationReason` | ❌ | Free text — may carry staff commentary. |
| `cancelledAt` / `cancelledBy` | ❌ | `cancelledBy` reveals the staff actor id; `cancelledAt` dropped for tightness (`status` conveys the state). |
| `previousBookingId` | ❌ | Internal reschedule linking id, low display value. |
| `customerId` | ❌ | Scope key — survives as identifier; not a display field. |

**Shipped whitelist:** `["serviceId", "startAt", "endAt", "status", "notes", "depositAmount", "depositPaidAt"]`.

## Declarative vs imperative

**Decision: unchanged from Wave 1 — fully declarative, pure-data manifest, zero I/O.**
The two new collections are additional constant entries with a `fields` array; the
provider still branches only on `$subject['audience']` (server-derived per ADR-005).
Projection is enforced portaliq-side after per-row verification — the provider does
NOT filter rows or query OR (that would duplicate the authz path, ADR-022, and break
the duck-typed provider's dependency-free inertness guarantee). Rejected, as in Wave 1:
an imperative provider that queries OR to tailor fields per subject, and reuse of the
bespoke `PortalScopeResolver` (couples to retirement-bound code + adds constructor deps).

## Seed Data (unit-test fixtures — nil-pattern UUIDs only)

Unchanged from Wave 1: tests construct the provider directly (no container) and feed
synthetic subjects on the nil-UUID pattern so fixtures are self-evidently fake and
can never collide with live data:

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

No OR seed objects are needed: the provider performs no I/O. Live-portal seeding
(a portalAccount with `claims.pipelinq.clientId` / `customerUid`) belongs to portaliq's
own e2e environment, keyed by the Wave-1 claim-names contract.

## Risks

- If a future register edit adds a staff-only field to `contactmoment` or `booking`,
  the projection whitelist is an ALLOWLIST, so the new field stays hidden by default —
  strictly safer than the Wave-1 full-row exposure this change replaces. The
  register-drift pin test still fails if a *whitelisted* field is renamed/removed.
- Claim names remain load-bearing (`clientId`, `customerUid`); no new claim is
  introduced by this change — both reuse Wave-1 claims 1:1.
- `summary` (contactmoment) is deliberately withheld; adding it later needs product
  sign-off that agents never place internal commentary there.
