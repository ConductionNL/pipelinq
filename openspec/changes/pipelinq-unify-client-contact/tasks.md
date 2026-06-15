# Tasks — Pipelinq Unify Client / Contact / Contactmoment

## Phase 0: Deduplication Check (ADR-012)

- [x] Confirm `client` stores duplicate identity — VERIFIED `lib/Settings/pipelinq_register.json` `/components/schemas/client` (REQ `name`,`type`) carries `name`, `type`, `email`, `phone`, `address`, `website`, `industry`, `notes`, `contactsUid`; the identity subset (`name`/`email`/`phone`/`address`/`website`) is already written to the NC vCard by `ContactVcardPropertyBuilder::addClientProperties()` (`name→FN`, `email→EMAIL`, `phone→TEL`, `type==organization→ORG`). → identity is a duplicate; demote to mirror, keep CRM fields.
- [x] Confirm `contact` stores duplicate identity — VERIFIED `/components/schemas/contact` (REQ `name`) carries `name`, `email`, `phone`, `role`, `client`, `contactsUid`, plus `marketingConsent`/`doNotContact`/`verifiedBSN`/`brpPersoonId`/`geheimhouding`. `name`/`email`/`phone` mapped to the vCard by `ContactVcardPropertyBuilder::addContactProperties()`. → identity is a duplicate; demote to mirror, keep `role`/parent-link/flags.
- [x] Confirm `contactmoment` is an interaction log, not identity — VERIFIED `/components/schemas/contactmoment` (REQ `subject`,`channel`) has channel/outcome/agent/contactedAt + a `client` **UUID** link. → keep schema; add canonical `contactsUid` link, keep `client` as back-reference.
- [x] Confirm the contact-sync capability already exists & is keyed by `contactsUid` — VERIFIED archived `openspec/changes/archive/2026-02-26-contacts-sync`, `lib/Service/ContactSyncService.php`, `ContactVcardService::syncToContacts()`, `ContactImportService::importAsClient/importAsContact()`, `ContactLinkedUidsService::getLinkedContactsUids()`, `ContactSyncController`. → reuse; no new sync engine, no new key.
- [x] Confirm NO new party/customer/organisation schema is introduced — identity moves OUT to the NC addressbook (contract #2), not into a new pipelinq schema.
- [x] Confirm contact matching uses the integration registry — VERIFIED the `contacts-actions` provider on OR `pluggable-integration-registry` (ADR-019) is already consumed by the import flow; the migration reuses it (no bespoke HTTP, ADR-022).
- [x] Conclusion: demote duplicate identity fields on `client`/`contact` to mirrors (1 fragment), add the canonical `contactsUid` link on `contactmoment` (same fragment), add 1 idempotent migration repair, keep all three nav entries. No capability re-implemented.

## Phase 1: Schema fragment — demote identity, key by contactsUid (REQ-PUCC-001, 002, 003)

- [ ] Add `lib/Settings/register.d/15-unify-client-contact.json` (override fragment targeting the existing `client`/`contact`/`contactmoment` slugs — the pattern `90-master-data-management.json` uses).
- [ ] `client`: make `contactsUid` REQUIRED; add CRM relationship fields `lifecycleStage` (enum), `segment`, `accountOwner` (NC uid), `accountStatus` (enum, facetable); flag `name`/`email`/`phone`/`address`/`website` as denormalised read-only mirrors (`readOnly:true`, marker `x-pipelinq-denormalised:true`). Keep `type`/`industry`/`notes` as authoritative CRM fields.
- [ ] `contact`: make `contactsUid` REQUIRED; flag `name`/`email`/`phone` as denormalised read-only mirrors; keep `role`, `client` (parent account), and all domain flags (`marketingConsent`/`doNotContact`/`verifiedBSN`/`brpPersoonId`/`geheimhouding`) authoritative.
- [ ] `contactmoment`: add `contactsUid` (canonical party link, facetable); keep `client` as a soft back-reference (do NOT remove). Do NOT add any identity fields.
- [ ] Verify the fragment does not clobber existing properties; `client`/`contact`/`contactmoment` resolve against the same slugs (no second register); `relationship` (which references `fromContact`/`toContact` by UUID) is unaffected.

## Phase 2: Identity sync reuses the existing pattern (REQ-PUCC-004)

- [ ] On create/update of a `client`/`contact`, ensure the linked NC contact exists and refresh the denormalised mirror fields from it via the EXISTING `ContactVcardService` / `ContactSyncService::syncToContacts()` — no new sync service, no new key.
- [ ] Resolve/match an existing NC contact through the `contacts-actions` integration provider (ADR-019) before creating one; never a hard-coded HTTP call (ADR-022).
- [ ] Make the NC contact authoritative for `name`/`email`/`phone`/`address`/`org`; the pipelinq mirror is read-only in the UI (`ClientDetail.vue`/`ContactDetail.vue` source identity from the contact, "edit identity" deep-links to the addressbook).

## Phase 3: Idempotent migration (REQ-PUCC-005, 006)

- [ ] Add `lib/Repair/UnifyClientContactIdentity.php` (idempotent; reads OR objects via `setRegister(slug)->setSchema(Name)->findAll([])`; POSITIONAL args for OCP service calls).
- [ ] For each `client`/`contact`: if `contactsUid` already set → no-op; else resolve an NC contact via `contacts-actions` matching on `email` first, then KvK/ORG (`type==organization`), else create one from the existing identity fields via the contact-sync builders. Store `contactsUid`; KEEP the identity fields as the initial mirror (never delete).
- [ ] For each `contactmoment`: resolve `contactsUid` from the linked `client`'s freshly-set `contactsUid`; set `contactmoment.contactsUid`; leave `contactmoment.client` untouched.
- [ ] Write one MDM `sourceRecord` (`sourceSystem: pipelinq-identity-unify`) per resolved object so the golden-record/dedup layer can collapse duplicate identities. Return a `{objectId → contactsUid}` audit map. Never overwrite a non-empty `contactsUid`; resumable on partial run.

## Phase 4: Nav + detail views (REQ-PUCC-007)

- [ ] Keep the three nav entries (`Clients` order 20, `Contacts` order 30, `Contactmomenten` order 70 in `src/manifest.json`) and their routes (`/clients`, `/contacts`, `/contactmomenten`) — they are distinct surfaces; no removal, no deep-link break.
- [ ] Refocus `ClientDetail.vue` as an account/relationship view and `ContactDetail.vue` as a contact-person role view, both sourcing identity from the linked NC contact (read-only mirror) per Phase 2.

## Phase 5: Verification

- [ ] `cd pipelinq && openspec validate pipelinq-unify-client-contact --strict` passes (exit 0).
- [ ] Hydra gates pass (spdx + route-auth on any touched controller, no redundant-controller wrapping ObjectService, notification-dialect on `client`/`contact`, spec-coverage on changed methods, dashboard-antipattern unaffected).
- [ ] Confirm non-destructive: re-running the repair is a no-op for already-linked objects; no identity field deleted; `contactmoment.client` preserved.
- [ ] Confirm the existing `client.x-openregister-notifications.newContact` / `contact` notification and the `marketingConsent`/BRP flows still resolve after `contactsUid` becomes required.
