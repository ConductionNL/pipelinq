## Context

`client` and `contact` (`lib/Settings/pipelinq_register.json`, extended by `lib/Settings/register.d/`) each carry exactly one `email` and one `phone`, both marked `readOnly`/`x-pipelinq-denormalised` and described as mirrors of a Nextcloud Contact vCard. In practice the Contacts sync (`ContactVcardService`, `ContactVcardPropertyBuilder`, `ContactDataBuilder`, `ContactSyncService`) is a two-way, last-writer-wins sync, not a one-directional mirror: import (`ContactDataBuilder`) reads the vCard into the object, and write-back (`ContactVcardPropertyBuilder` + `ContactVcardWriterService`) pushes the object's current fields back onto the vCard, triggered explicitly (not automatically) after a save. The detail pages (`ClientDetail`, `ContactDetail` in `src/manifest.json`) are declarative `type: "detail"` pages with no page-host Vue component — everything beyond the generic identity `data` widget is a `bodyWidgets` entry, a self-fetching `kind: 'section'` component. The segment rule builder (`SegmentService`) validates and evaluates a rule tree against a flat schema-properties map with no support for reaching into an array-of-objects property.

Proposal: see `proposal.md`. Requirements: see `specs/contact-channel-details/spec.md`, `specs/contacts-sync/spec.md`, `specs/marketing-segmentation/spec.md`.

## Goals / Non-Goals

**Goals**: typed multi-value channels on both schemas; both-directions vCard mapping; a display + edit surface on the two detail pages that does not grow the gate-29 custom-widget ratchet; dotted-field rule support so a segment can target "has a mobile number" once a rule-builder UI exists to use it.

**Non-Goals**: building the segment rule-builder page itself (`SegmentBuilder.vue`/`SegmentRuleNode.vue` exist but nothing wires real `fieldOptions` into them yet — a `forthcoming SegmentEditor` per `src/registry.js`; out of scope here). Enforcing "exactly one primary entry" at the schema level (OpenRegister's JSON-schema validation has no cross-array-element constraint for this; enforced by convention in the save path instead, see Decisions). Verified/followed-by-us/follows-us provenance tracking (these are plain booleans a user or a future integration sets; no verification workflow is built here).

## Decisions

### The scalar `email`/`phone` mirror is kept in the save path, not declaratively

ADR-031's `x-openregister-calculations` computes a derived property from other fields at READ time (cacheable, not persisted); it has no documented grammar for "select the array entry where `primary == true`", and even if it did, a read-time calculation would not solve this: the scalar fields are read by other, unrelated consumers (vCard write-back's fallback, any existing report or integration reading `client.email` directly) as **stored** values, not calculated on the fly. So `ContactChannelsSection.persist()` (frontend) recomputes `email`/`phone` from the primary entry (or the first entry when none is marked primary) of the patched array and includes it in the same `saveObject()` call that writes the array — one round trip, one source of truth for that save. This is a frontend-only guarantee: a future API-only writer that sets `emails[]` without going through this component would not get the mirror for free. Documented as a DEFERRED_QUESTION below rather than solved with a repair-step-shaped safety net, which would only paper over the same gap on a delay.

### Detail-page display is a `kind: 'section'` body widget, not a `kind: 'widget'` custom widget

The generic `data` widget's array-of-objects rendering (`CnObjectDataWidget`) produces a plain, capped mini-table with no links and no styling — it cannot express "clickable `mailto:`/`tel:`/profile link with a kind chip" (verified: zero `<a>`/`mailto:`/`tel:` usage in that component). A `kind: 'widget'` registry entry would satisfy the display requirement but costs a permanent slot against gate-29's custom-widget ratchet (fleet-wide ADR-049 discipline: built-ins first). `bodyWidgets` (`kind: 'section'`) is the existing, already-used escape hatch for exactly this shape of requirement (`ContactRelationships`, `ActivityTimeline`, `ClientBillingHandoffSection`, …) and is not counted by that gate. `ContactChannelsSection.vue` follows that pattern: self-fetches the full object by `entityId`/`entityType` props (token-resolved `@objectId`), because OpenRegister's object PUT replaces the whole representation — a partial `{emails: [...]}` payload would drop every other field, so the section always saves the full merged entity, not just the changed array.

### Two single-item modals, not one combined form

`src/modals/ContactEmailPhoneModal.vue` (shared for both `emails[]` and `phones[]` via a `channelType` prop, since they have an identical shape) and `src/modals/ContactSocialProfileModal.vue` follow the existing `ProductVariantDialog.vue` convention: a pure form that emits the built entry back to the caller, which owns splicing it into the array and persisting. This keeps each modal small, keeps persistence logic in one place (the section, where the primary-mirror decision above also lives), and matches the majority `NcDialog`-in-`src/modals/` convention already used by 19 of 21 existing files in that directory (2 use `NcModal`; the gate does not distinguish).

### vCard TYPE mapping is lossy for `whatsapp`, by design

`kind: "whatsapp"` and `kind: "mobile"` both write `TEL;TYPE=CELL` (vCard/RFC 6350 has no WhatsApp-specific TYPE token in the shape `AddressBookImpl::createOrUpdate()` consumes — a plain `{value, type}` pair per entry, one TYPE parameter). A WhatsApp number therefore round-trips as `kind: "mobile"` on the next import from that vCard. Accepted: the alternative (inventing a non-standard multi-token TYPE and hoping Sabre/VObject serialises and re-parses it symmetrically) could not be verified without a running Nextcloud instance to exercise the real CardDAV backend, and a wrong guess there is silent data corruption, not a loud failure. `socialProfiles[]`'s `X-SOCIALPROFILE` mapping does not have this problem — `network` values are already vCard-safe lowercase tokens and round-trip exactly.

### Primary/verified selection on import has no PREF signal

`IContactsManager::search(..., ['types' => true])` returns `{type, value}` pairs with no `PREF` (preference order) information — `AddressBookImpl::vCard2Array()` only extracts `TYPE`, never `PREF`, even with `withTypes` on. So "first entry in the array" is the only available primary signal on import, and `verified` always imports as `false` (there is no vCard property carrying it). Both are documented as DEFERRED_QUESTIONS.

### Segment dotted-path projection is "any element matches", uniformly

`evaluateProjectedLeaf()` treats every operator the same way for a dotted field: project the sub-property across every array element and return true if ANY element's handler call is true. This mirrors the existing `contains`/`containsAny` semantics already used for plain array fields (`valueContains()`), extended to array-of-objects. An "all elements match" or "count matches" mode was considered and rejected as unneeded speculative surface — no requirement in `docs/Technical/marketing-architecture.md` calls for it, and it can be added as a new operator later without touching this shape.

## Risks / Trade-offs

- **[Risk]** A caller that writes `emails[]`/`phones[]` directly via the API (bypassing `ContactChannelsSection`) leaves the legacy scalar mirror stale. → **Mitigation**: the backfill repair step and the write-back vCard mapping both already prefer the array over the scalar when present, so the vCard and any array-reading consumer stay correct; only a scalar-only reader (a hypothetical future integration) would see a stale value. No such reader exists in this codebase today.
- **[Risk]** `whatsapp`-kind entries lose their distinction on a vCard round-trip. → **Mitigation**: documented above; Pipelinq's own stored `kind` is authoritative between two Pipelinq saves — only an external edit via the Nextcloud Contacts app, followed by a re-import, would degrade it.
- **[Risk]** The segment dotted-path feature has no UI consumer yet, so it ships untested by a human until `SegmentEditor` lands. → **Mitigation**: full PHPUnit coverage of validate/evaluate for the new path; the `@e2e exclude` on that requirement states this explicitly so the gap is visible, not silent.

## Migration Plan

- **Deploy**: the register fragment merges additively; OpenRegister re-imports on install/update. `BackfillContactChannelArrays` runs in `post-migration`, after `UnifyClientContactIdentity` (which is what guarantees `contactsUid`/identity fields are already settled) and before `SeedTrustConfigurationRows`. It does not run in `install` (a fresh install has no legacy scalar values to backfill from).
- **Rollback**: delete the register fragment and the repair-step registration; re-import. The `emails[]`/`phones[]`/`socialProfiles[]` data written by the backfill or by users remains on the objects (OpenRegister does not delete data for a removed schema property) but is simply no longer validated or displayed — no destructive rollback step needed.
- **Back-compat**: purely additive; every existing consumer of `client.email`/`contact.phone` continues to read the same field, now kept current by either the array-aware write-back or (when arrays are empty) the unchanged legacy path.

## DEFERRED_QUESTIONS

- Whether a future scalar-mirror guarantee should also exist server-side (e.g. an `ObjectEventListener`-driven post-save correction) for API-only writers that bypass `ContactChannelsSection`. Not built here — no such caller exists yet, and the existing event-listener infrastructure is post-save (would need a second write), not pre-save.
- `preferredChannel`/`timezone`/`language` are schema-declared and readable everywhere (including in `ContactChannelsSection`'s read-only summary line and in seed data), but have no dedicated edit UI in this change — editing them was judged lower-value than the channel arrays for phase 1 and can go through the ordinary schema-driven edit path once available, or a small follow-up modal.
- The exact `preferredChannel` enum (`email`/`phone`/`whatsapp`/`linkedin`/`x`/`mastodon`/`bluesky`/`other`) is a judgement call — `docs/Technical/marketing-architecture.md` does not enumerate it. Chosen to mirror the union of `emails`/`phones`' `kind` values (minus `work`/`private`/`mobile`, which are not "channels" in this sense) plus the social networks a contact might prefer for outreach.
- vCard `TYPE` → `kind` mapping recognises `WORK`, `HOME`, `CELL`, `MOBILE`, `IPHONE` (Apple's non-standard token); anything else imports as `other`. Not exhaustive against every vendor's TYPE vocabulary — extend `ContactDataBuilder::VCARD_TYPE_TO_KIND` if a gap is found in practice.
