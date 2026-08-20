# Proposal: portal-projected-collections

**Tracking issue**: Conduction/pipelinq#343 (Wave 2 of the portaliq fleet rollout — lifts two documented Wave-1 exclusions; do not close the issue from this change).

kind: code

## Summary

Extend pipelinq's merged `PortalContributionProvider` (Wave 1, `portal-contribution`)
with the **two collections that were fail-closed excluded** because Wave-1 portaliq
served whole rows and pipelinq had staff-only fields on those schemas. portaliq now
ships **read-side field projection** (merged 2026-07-06): a collection may declare
`fields: ["a","b"]` and portaliq whitelist-projects each row **after** its per-row
scope verification — identifiers always survive, and a malformed `fields` declaration
degrades safely to identifiers-only. With that primitive available we can now expose:

- **`contactmoment`** to the `client` audience, scoped by `client` via the `clientId`
  claim, projected to `subject`, `channel`, `outcome`, `contactedAt` — dropping the
  internal `notes`/`channelMetadata`/`duration`, the agent identity and every CTI
  call internal (recording URL, disposition notes) the Wave-1 exclusion cited.
- **`booking`** to the `customer` audience, scoped by `customerId` via the
  `customerUid` claim, projected to `serviceId`, `startAt`, `endAt`, `status`,
  `notes`, `depositAmount`, `depositPaidAt` — dropping the schema's own explicitly
  `Staff-only` `internalNotes`, the audit `statusHistory`, `resourceAssignments`,
  cancellation-actor and provenance fields. Gated at `minTrust: substantial` because
  the customer-facing `notes` may carry special-category data (the schema's example
  is allergies).

## Why

- The two surfaces were excluded in Wave 1 **only** for lack of field projection
  (see `portal-contribution/design.md` exclusions). That blocker is now gone, so the
  exclusions are lifted with explicit, minimal whitelists instead of whole rows.
- Field projection keeps pipelinq the authority on what is client/customer-safe: the
  provider declares the whitelist; portaliq enforces it after verification.
- `berichtenboxMessage` stays excluded — it is BSN-scoped, not a contact/customer
  UUID domain ref, and BSN may never become a portal scoping claim.

## Scope (this change — code only)

- `lib/Portal/PortalContributionProvider.php` — add the two field-projected
  collections; update the class/method docblocks that documented the exclusions.
- `tests/Unit/Portal/PortalContributionProviderTest.php` — update the collection-set
  and register-drift pins for the new entries; add two tests asserting the exact
  projection whitelists and the absence of every staff-only/internal field.
- No register/schema edits: every scopeField and every projected field already exists
  at HEAD (`contactmoment.client`, `booking.customerId`, and the whitelisted read
  fields — verified in design.md).

## Out of scope (later phases on Conduction/pipelinq#343)

- Retiring pipelinq's bespoke portal (`lib/Controller/Portal*`, `lib/Service/Portal/*`).
- `endpoint` actions and any create action on `contactmoment`/`booking` (Wave-1 rule
  still holds: create actions stay a strict intake whitelist, none added here).
- A Berichtenbox inbox surface (BSN-scoped — permanently out unless the contract gains
  a BSN-free delivery scope).

## Depends on

- portaliq with read-side field projection installed (rendering side); pipelinq stays
  inert without portaliq.
- The merged `portal-contribution` change (this change adds to that capability).
