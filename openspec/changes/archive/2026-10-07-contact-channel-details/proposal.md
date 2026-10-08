## Why

The marketing programme (phase 1, `docs/Technical/marketing-architecture.md`) needs a mailing list to reach more than one address per client, a segment rule that targets "has a mobile number" or "has a LinkedIn profile", and a place to record which channel and language a contact prefers before Pipelinq sends anything. Today `client` and `contact` carry exactly one `email` and one `phone`, both denormalised mirrors of a single Nextcloud Contact vCard value, with no way to record a second address, a social profile, or a channel preference.

## What Changes

- `client` and `contact` gain typed `emails[]` and `phones[]` (`kind`: work/private/mobile/whatsapp/other, `value`, `primary`, `verified`), `socialProfiles[]` (`network`: linkedin/x/mastodon/bluesky/facebook/instagram/threads/tiktok/youtube/other, `handle`, `url`, `verified`, `followedByUs`, `followsUs`), plus `preferredChannel`, `timezone` and `language`. The existing single `email`/`phone` fields are kept as the primary-entry mirror so nothing downstream that already reads them breaks.
- A non-destructive repair step backfills `emails[0]`/`phones[0]` from the legacy scalar values on upgrade, so an existing record shows a channel immediately rather than an empty list.
- The Contacts sync (vCard write-back and import) maps the typed arrays to/from multi-valued `EMAIL`/`TEL` (with `TYPE`) and `X-SOCIALPROFILE` vCard properties, both directions.
- ClientDetail and ContactDetail gain a body section listing the channels as a compact, linked list (`mailto:`/`tel:`/profile URL) with kind/network chips, plus add/edit/remove via two small modals.
- The segment rule builder's field resolution and rule evaluation gain support for a dotted `arrayProp.subProp` field path (e.g. `phones.kind`, `socialProfiles.network`), so a rule can target "has at least one phone of kind mobile" against these (and any future) array-of-object schema properties.
- **Fixed in passing**: `SegmentService::resolveSchemaProperties()` called `SchemaMapper::find()` with a `published:` named argument OpenRegister dropped in commit `ea99a5004`; every rule validation/evaluation over HTTP has been silently failing closed since. Fixed as part of extending this exact method for the dotted-path work (see design.md).

## Capabilities

### New Capabilities
- `contact-channel-details`: typed multi-value emails/phones/social profiles and channel/locale preferences on `client` and `contact`, their detail-page display and edit UI, and the upgrade backfill.

### Modified Capabilities
- `contacts-sync`: vCard write-back and import now map the typed channel arrays to/from multi-valued `EMAIL`/`TEL`/`X-SOCIALPROFILE` vCard properties, in addition to the existing single-value mapping.
- `marketing-segmentation`: the rule builder's field resolution and evaluation now support a dotted `arrayProp.subProp` field path into an array-of-objects schema property.

## Impact

- **Schema register**: new fragment `lib/Settings/register.d/16-contact-channel-details.json` (client, contact) — additive, back-compatible.
- **Backend**: `ContactDataBuilder`, `ContactVcardPropertyBuilder`, `ContactSyncService` (contacts-sync mapping); `SegmentService` (dotted field paths + the pre-existing bug fix); new repair step `BackfillContactChannelArrays`.
- **Frontend**: new `ContactChannelsSection.vue` body section (registered `kind:'section'`, not `kind:'widget'` — does not grow the gate-29 custom-widget ratchet), two new modals (`ContactEmailPhoneModal.vue`, `ContactSocialProfileModal.vue`), `src/manifest.json` (ClientDetail/ContactDetail `bodyWidgets`).
- **l10n**: new schema property titles/descriptions translated in `l10n/en.json`/`l10n/nl.json`; schema-l10n ratchet does not grow.
- **Demo data**: `lib/Settings/demo_seed_data.json` gains a LinkedIn handle, a Mastodon handle and a mobile number on a few existing demo contacts.
- **No breaking change**: every new property is optional and additive; the legacy `email`/`phone` fields are unchanged in shape and continue to be populated.
