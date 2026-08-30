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

- [x] Add `lib/Settings/register.d/15-unify-client-contact.json` (override fragment targeting the existing `client`/`contact`/`contactmoment` slugs — the pattern `90-master-data-management.json` uses).
- [x] `client`: make `contactsUid` REQUIRED (`required: ["name","type","contactsUid"]`); add CRM relationship fields `lifecycleStage` (enum), `segment`, `accountOwner` (NC uid), `accountStatus` (enum, facetable); flag `name`/`email`/`phone`/`address`/`website` as denormalised read-only mirrors (`readOnly:true`, marker `x-pipelinq-denormalised:true`). Keep `type`/`industry`/`notes` as authoritative CRM fields.
- [x] `contact`: make `contactsUid` REQUIRED (`required: ["name","contactsUid"]`); flag `name`/`email`/`phone` as denormalised read-only mirrors; keep `role`, `client` (parent account), and all domain flags (`marketingConsent`/`doNotContact`/`verifiedBSN`/`brpPersoonId`/`geheimhouding`) authoritative.
- [x] `contactmoment`: add `contactsUid` (canonical party link, facetable); keep `client` as a soft back-reference (NOT removed). No identity field added.
- [x] Verified via deep-merge simulation: fragment does not clobber existing properties (only the named props deep-merge / are flagged), `client`/`contact`/`contactmoment` resolve against the same slugs (no second register), `relationship` (`fromContact`/`toContact` by UUID) is unaffected, and the `contact.x-openregister-notifications.newContact` block is preserved.

## Phase 2: Identity sync reuses the existing pattern (REQ-PUCC-004)

- [x] The repair refreshes the linked NC contact via the EXISTING `ContactVcardService::syncToContacts()` (reused as-is) — no new sync service, no new key. On create/update the existing app-side write-back path (`/api/contacts-sync/write-back` → `ContactSyncService::syncToContacts`) continues to keep the mirror in sync.
- [x] Resolve/match an existing NC contact via the Contacts `IManager` surface (the same surface the `contacts-actions` provider fronts) on `email` then KvK/ORG before creating one; never a hard-coded cross-app HTTP call (ADR-022).
- [x] Make the NC contact authoritative for `name`/`email`/`phone`/`address`/`org`; the pipelinq mirror is read-only in the UI — `ClientDetail.vue`/`ContactDetail.vue` show identity as a read-only mirror with an "Edit in Contacts" deep-link to the addressbook.

## Phase 3: Idempotent migration (REQ-PUCC-005, 006)

- [x] Added `lib/Repair/UnifyClientContactIdentity.php` (idempotent; reads OR objects for real via `setRegister->setSchema->findAll([])`; POSITIONAL args on the `IManager::search` OCP call; registered in `appinfo/info.xml` post-migration).
- [x] For each `client`/`contact`: if `contactsUid` already set → no-op; else resolve an NC contact (email first, then KvK/ORG for `type==organization`), else create one from the existing identity fields via `ContactVcardService`. Store `contactsUid`; KEEP the identity fields as the initial mirror (never deleted).
- [x] For each `contactmoment`: resolve `contactsUid` from the linked `client`'s freshly-set `contactsUid` (matched on id/uuid/slug refs); set `contactmoment.contactsUid`; leave `contactmoment.client` untouched.
- [x] Writes one MDM `sourceRecord` (`sourceSystem: pipelinq-identity-unify`) per resolved object; `migrate()` returns counters + a `{objectId → contactsUid}` audit map. Never overwrites a non-empty `contactsUid`; resumable on partial run; fail-safe (skips cleanly when OR/Contacts unavailable, never marks complete or deletes data).

## Phase 4: Nav + detail views (REQ-PUCC-007)

- [x] Kept the three nav entries (`Clients` order 20, `Contacts` order 30, `Contactmomenten` order 70 in `src/manifest.json`) and their routes (`/clients`, `/contacts`, `/contactmomenten`) — untouched.
- [x] Refocused `ClientDetail.vue` (Identity = read-only mirror card + new "Account & relationship" card surfacing `lifecycleStage`/`segment`/`accountOwner`/`accountStatus`/`industry`) and `ContactDetail.vue` (Contact-person card: identity read-only mirror, `role`+parent-account authoritative), both with an "Edit in Contacts" addressbook deep-link.

## Phase 5: Verification

- [x] `openspec validate pipelinq-unify-client-contact --strict` passes (exit 0; "Change is valid").
- [x] All 24 hydra gates green when scoped to the worktree (spdx, route-auth, redundant-controller, notification-dialect, spec-coverage, dashboard-antipattern, nc-input-labels, etc.). `php -l` clean on the new repair. (Deep PHPCS/Psalm deferred to CI — vendor deps not installed in the worktree; new file follows the sibling-repair conventions exactly.)
- [x] Non-destructive confirmed by construction: re-running the repair is a no-op for already-linked objects (existing `contactsUid` never overwritten); no identity field deleted; `contactmoment.client` preserved.
- [x] `contact.x-openregister-notifications.newContact` block + `marketingConsent`/BRP flags confirmed preserved by the merge simulation after `contactsUid` becomes required.
