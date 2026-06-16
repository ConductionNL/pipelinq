# Proposal: pipelinq-unify-client-contact

kind: consolidation + identity-deduplication (ADR-012 deduplication, ADR-022 apps-consume-or-abstractions, ADR-019 integration-registry, ADR-031 schema-declarative-business-logic, ADR-037 modular-config-fragments — CROSS-APP INTERFACE CONTRACT #2: people/organisations/contacts = Nextcloud addressbook keyed by `contactsUid`)

## Summary

pipelinq exposes **three overlapping people-surfaces** in its top-level navigation — **Clients**, **Contacts**, and **Contactmomenten** (contact moments / interactions). Two of them (`client`, `contact`) store **duplicate person/organisation IDENTITY** (`name`, `email`, `phone`, `address`, `website`) that already lives — or should live — in the Nextcloud addressbook. Per **CROSS-APP INTERFACE CONTRACT #2**, a person/org identity is a **Nextcloud Contact** (`OCP\Contacts\IManager`), keyed by `contactsUid`; pipelinq must keep only the app-specific *relationship/role* record and the *interaction log*, not a parallel identity store.

This change makes the NC addressbook the single identity store and demotes the three surfaces to their genuine pipelinq-specific roles, **reusing the contact-sync pattern that already exists** (`ContactSyncService`, `ContactVcardService`, `ContactImportService`, `ContactLinkedUidsService`, `ContactDataBuilder`, `ContactVcardPropertyBuilder`, `contactsUid`). It invents **no new sync engine and no new `contactsUid` key**.

**Role → owner after this change**

| Surface (today) | Today's role | After | Identity key |
|---|---|---|---|
| `client` schema | identity **+** CRM relationship (duplicates name/email/phone/address) | **CRM relationship/role record** (account: type, industry, lifecycle, segment, owner) keyed by `contactsUid`; identity fields demoted to denormalised read-only mirrors | `contactsUid` → NC Contact |
| `contact` schema | identity of a contact person (duplicates name/email/phone) | **contact-person role record** (role, parent account, marketing/BRP/privacy flags) keyed by `contactsUid`; identity fields demoted to denormalised mirrors | `contactsUid` → NC Contact |
| `contactmoment` schema | interaction log linked to `client` by UUID | **interaction log** linked by `contactsUid` (canonical) — identity untouched, only the link is normalised | `contactsUid` → NC Contact |
| Nav: `Clients` (order 20) | top-level | **kept** (the CRM relationship list — now an account/relationship view, identity sourced from addressbook) | — |
| Nav: `Contacts` (order 30) | top-level | **kept** (contact-person role list) | — |
| Nav: `Contactmomenten` (order 70) | top-level | **kept** (interaction log) | — |

The three *nav entries stay* — they are genuinely distinct surfaces (relationship vs. role vs. interaction). What is removed is the **duplicate identity STORAGE**: `name`/`email`/`phone`/`address`/`website` stop being authoritative on `client`/`contact` and become denormalised mirrors synced from the NC Contact. Pages stay routable; no deep link breaks.

## Dedup rationale (ADR-012)

Phase 0 verified against the live pipelinq code (`lib/Settings/pipelinq_register.json`, `/components/schemas`):

- **`client`** (slug `client`, REQ `name`,`type`) carries identity fields `name`, `type`, `email`, `phone`, `address`, `website`, `industry`, `notes`, `contactsUid`. The identity subset (`name`/`email`/`phone`/`address`/`website`) is **exactly what the NC vCard already holds** — `ContactVcardPropertyBuilder::addClientProperties()` already maps `name→FN`, `email→EMAIL`, `phone→TEL`, and `type==organization → ORG`. → identity is a **duplicate**; demote to mirror, keep the CRM-specific fields (`type`, `industry`, lifecycle/segment).
- **`contact`** (slug `contact`, REQ `name`) carries `name`, `email`, `phone`, `role`, `client`, `contactsUid`, plus domain flags (`marketingConsent`, `doNotContact`, `verifiedBSN`, `brpPersoonId`, `geheimhouding`). `name`/`email`/`phone` duplicate the vCard (`ContactVcardPropertyBuilder::addContactProperties()` already maps them + derives `ORG` from the parent client). → identity is a **duplicate**; demote to mirror, keep `role` + the parent-account link + the domain flags.
- **`contactmoment`** (slug `contactmoment`, REQ `subject`,`channel`) is a genuine interaction log (channel/outcome/agent/contactedAt) — **not** an identity store. Its only flaw is linking to the party by the `client` **UUID** rather than the canonical `contactsUid`. → keep the schema; add the `contactsUid` link (canonical), keep `client` as a soft back-reference.
- **The contact-sync capability already exists and is already keyed by `contactsUid`**: `openspec/changes/archive/2026-02-26-contacts-sync`, `lib/Service/ContactSyncService.php` (search/import/sync), `ContactVcardService::syncToContacts()`, `ContactImportService::importAsClient/importAsContact()`, `ContactLinkedUidsService::getLinkedContactsUids()`, `ContactSyncController`. → this change **reuses** it; it does NOT add a new sync service or a new key.
- **No new party/customer/organisation schema** is introduced — that is precisely what contract #2 forbids. Identity moves *out* to the addressbook, not into a new pipelinq table.

No capability is re-implemented. This change demotes duplicate identity fields on two existing schemas, normalises one link on a third, and adds one idempotent migration repair that resolves existing `client`/`contact` objects to NC contacts (match on email / KvK-ORG) and re-keys interaction records — non-destructively.

**Depends on:**
- pipelinq `contacts-sync` (implemented, archived `2026-02-26-contacts-sync`) — supplies `ContactSyncService`/`ContactVcardService`/`contactsUid` reused here; no new sync engine.
- OpenRegister `pluggable-integration-registry` (ADR-019) — the `contacts-actions` provider used to match/resolve an existing NC contact by email/KvK before creating one (same provider `pipelinq-product-vendor-master` and the contact-sync flow already use).
- pipelinq `master-data-management` (`lib/Settings/register.d/90-master-data-management.json`) — the MDM `masterEntity`/`sourceRecord` golden-record layer that already extends `client`/`contact`; the migration writes provenance there.
