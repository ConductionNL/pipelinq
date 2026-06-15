# Design — pipelinq unify client / contact / contactmoment

## Context

pipelinq is the CRM / sales / service-desk app. Its top-level nav exposes three people-surfaces — **Clients**, **Contacts**, **Contactmomenten** — and two of them (`client`, `contact`) store person/organisation IDENTITY (`name`, `email`, `phone`, `address`, `website`) that the Nextcloud addressbook already holds. Per CROSS-APP INTERFACE CONTRACT #2, identity is a Nextcloud Contact keyed by `contactsUid`; pipelinq must keep only the *relationship/role* record and the *interaction log*.

pipelinq already has a complete contact-sync layer keyed by `contactsUid`:

- `ContactSyncService` — search NC addressbook, import a contact as `client`/`contact`, sync a pipelinq object back to the addressbook.
- `ContactVcardService::syncToContacts(objectType, objectId)` — writes a pipelinq client/contact out to a vCard.
- `ContactImportService::importAsClient()/importAsContact()` — creates a pipelinq object from an NC contact.
- `ContactVcardPropertyBuilder` — maps `name→FN`, `email→EMAIL`, `phone→TEL`, `type==organization→ORG`, derives `ORG` for a contact from its parent client.
- `ContactLinkedUidsService::getLinkedContactsUids()` — the set of `contactsUid` values already linked.
- `ContactSyncController` — the HTTP surface.

This change **reuses every one of those** and adds no new sync engine or key.

## Key decisions

### D1 — Keep the three nav entries; remove only the duplicate identity STORAGE

The three surfaces are genuinely distinct: a CRM **relationship/account** (`client`), a **contact-person role** within an account (`contact`), and an **interaction** with a party (`contactmoment`). They are not duplicates of each other and stay as separate top-level nav entries (`Clients` order 20, `Contacts` order 30, `Contactmomenten` order 70 in `src/manifest.json`). What *is* a duplicate is the **identity data** (`name`/`email`/`phone`/`address`/`website`) physically stored on `client`/`contact`, which the NC vCard already holds. We demote those fields to **denormalised read-only mirrors** synced from the NC Contact (the contact is authoritative), exactly as `pipelinq-product-vendor-master` does for `supplier.displayName`.

### D2 — `client` becomes a CRM relationship/role record keyed by `contactsUid`

`client` keeps its CRM-specific fields (`type`, `industry`, `notes` as CRM annotation) and gains the relationship attributes that make it an account record rather than an identity card — `lifecycleStage`, `segment`, `accountOwner`, `accountStatus`. `contactsUid` becomes **REQUIRED** (the FK to the NC Contact = identity). The identity fields `name`, `email`, `phone`, `address`, `website` are marked as **denormalised mirrors** (`x-pipelinq-denormalised: true`, `readOnly`, `visible:false` where appropriate) kept in sync from the contact by `ContactVcardService`. No identity field is deleted (non-destructive); they simply stop being authoritative. This is an **extension fragment** `lib/Settings/register.d/15-unify-client-contact.json` that targets the existing `client`/`contact` slugs (the established override pattern used by `90-master-data-management.json`), so no second register is created.

### D2-bis — `contact` becomes a contact-person role record keyed by `contactsUid`

`contact` keeps `role`, the parent-account link (`client`), and all domain flags (`marketingConsent`, `doNotContact`, `verifiedBSN`, `brpPersoonId`, `geheimhouding`). `contactsUid` becomes **REQUIRED**. `name`, `email`, `phone` are demoted to denormalised mirrors. The BRP/privacy flags stay on `contact` (they are pipelinq governance state, not vCard identity).

### D3 — `contactmoment` links by `contactsUid` (canonical), not the `client` UUID

The interaction log already records channel/outcome/agent/contactedAt. Today it links the party via the `client` UUID (`contactmoment.client`). This change adds a canonical `contactsUid` link (the party the interaction was with) while keeping `client` as a soft back-reference for existing deep links. No identity is stored on `contactmoment`; only the link is normalised. This lets an interaction reference any addressbook party — including a contact-person — not just a `client` row.

### D4 — Identity sync reuses the existing pattern; no new engine

On create/update of a `client`/`contact`, the existing `ContactVcardService` ensures the linked NC contact exists and is the source of truth for `name`/`email`/`phone`/`address`/`org`; the denormalised mirror on the pipelinq object is refreshed from it. This is the same flow `ContactSyncService::syncToContacts()` already performs — no code is added beyond making `contactsUid` required and flagging the mirror fields declaratively (ADR-031). Matching/resolving an existing NC contact (rather than blindly creating one) goes through the OR `contacts-actions` integration provider (ADR-019), the same provider the import flow already uses — never a hard-coded HTTP call.

### D5 — Idempotent, non-destructive migration

`lib/Repair/UnifyClientContactIdentity.php` (idempotent) walks the existing `client` and `contact` objects and, for each:

1. If `contactsUid` is already set → no-op (idempotent).
2. Else resolve an NC contact via the `contacts-actions` provider, matching on `email` first, then on KvK/ORG (`type==organization` → ORG name) for organisations; if none matches, create one from the object's existing identity fields via `ContactVcardService` / `ContactImportService` builders.
3. Store the resolved `contactsUid` on the object; keep the (now denormalised) identity fields in place as the initial mirror value — **nothing is deleted**.
4. For `contactmoment` objects, resolve `contactsUid` from the linked `client` (via that client's freshly-set `contactsUid`) and set `contactmoment.contactsUid`; keep `contactmoment.client` untouched.
5. Write one MDM `sourceRecord` (`sourceSystem: pipelinq-identity-unify`) per resolved object so the golden-record/dedup layer can collapse duplicate identities later.

The repair returns a `{objectId → contactsUid}` map for audit. Re-running it is a no-op for already-linked objects. It never overwrites a non-empty `contactsUid` and never drops an identity field, so a partial run is safe to resume.

## Exact surfaces touched

| Artifact | File | Change |
|---|---|---|
| `client` schema | `lib/Settings/pipelinq_register.json` (`/components/schemas/client`) via fragment `lib/Settings/register.d/15-unify-client-contact.json` | `contactsUid` → required; add `lifecycleStage`, `segment`, `accountOwner`, `accountStatus`; flag `name`/`email`/`phone`/`address`/`website` as denormalised mirrors |
| `contact` schema | same fragment, `/components/schemas/contact` | `contactsUid` → required; flag `name`/`email`/`phone` as denormalised mirrors |
| `contactmoment` schema | same fragment, `/components/schemas/contactmoment` | add `contactsUid` (canonical party link); keep `client` as soft back-reference |
| Nav `Clients` | `src/manifest.json` menu id `Clients` (order 20), page id `Clients` route `/clients` | **kept**; relabelled/refocused as account/relationship list (identity from addressbook); route unchanged |
| Nav `Contacts` | `src/manifest.json` menu id `Contacts` (order 30), page id `Contacts` route `/contacts` | **kept**; contact-person role list; route unchanged |
| Nav `Contactmomenten` | `src/manifest.json` menu id `Contactmomenten` (order 70), page id `Contactmomenten` route `/contactmomenten` | **kept**; interaction log; route unchanged |
| Detail views | `src/views/clients/ClientDetail.vue`, `src/views/contacts/ContactDetail.vue` | source identity from the linked NC contact (read-only mirror); edit identity opens the addressbook |
| Migration | `lib/Repair/UnifyClientContactIdentity.php` (NEW) | idempotent identity resolution + re-keying |
| Sync (reused) | `ContactSyncService`, `ContactVcardService`, `ContactImportService`, `ContactLinkedUidsService`, `ContactVcardPropertyBuilder` | **unchanged** — reused as-is |

## Alternatives considered

- **A new `party`/`customer` identity schema** — rejected (contract #2 / ADR-012): the NC addressbook + `contactsUid` is the identity store; pipelinq keeps only relationship/role/interaction records.
- **Merge `client` and `contact` into one schema** — rejected: they are distinct roles (an account vs. a contact-person within it); merging loses the parent-account link and the per-person BRP/privacy flags. Only the *identity* overlap is removed.
- **Drop the `client`/`contact` identity fields outright** — rejected (destructive): they are kept as denormalised mirrors so existing list views, exports, and the migration's resolution step keep working; the contact remains authoritative.
- **Re-key `contactmoment` to `contactsUid` and delete `contactmoment.client`** — rejected: existing deep links and reports reference `client`; we add the canonical link and keep the back-reference.
- **A bespoke pipelinq identity-resolution HTTP call** — rejected (ADR-022/ADR-019): contact matching goes through the `contacts-actions` integration provider, the same one the existing import flow uses.

## Migration / rollout

1. Deploy the schema fragment (`15-unify-client-contact.json`) — additive + field-flag only; no data change; CRM/POS/lead UI unaffected because the identity fields still exist.
2. Make `contactsUid` required at the schema level only after step 3 has back-filled it (deploy the fragment with `contactsUid` not-yet-required, run the repair, then a follow-up fragment flips it to required) — OR ship the requirement and rely on the repair running in the same upgrade window. The repair runs idempotently on `occ upgrade`.
3. Run `UnifyClientContactIdentity` repair: resolve/create NC contacts for every `client`/`contact`, set `contactsUid`, re-key `contactmoment`, write MDM provenance. Re-runnable.
4. Frontend `ClientDetail`/`ContactDetail` switch to sourcing identity from the linked contact (read-only mirror; "edit identity" deep-links to the addressbook).

## Risks

- **Unmatched / ambiguous identities on migration** (two clients sharing an email, or no email at all). Mitigation: match email → KvK/ORG → create-new; write an MDM `sourceRecord` so the golden-record dedup layer can collapse duplicates afterwards; never overwrite an existing `contactsUid`; the repair is resumable.
- **Required `contactsUid` blocking saves before back-fill.** Mitigation: two-step requirement flip (D-rollout step 2) so no existing object is rejected mid-migration.
- **Mirror drift** between the pipelinq denormalised fields and the NC contact. Mitigation: the contact is authoritative; `ContactVcardService` refreshes the mirror on every sync; the mirror is read-only in the UI.
- **`contactmoment` referencing a contact-person rather than an account.** Mitigation: `contactsUid` is the canonical link (works for any party); `client` stays as the account back-reference so account-scoped reports still work.
