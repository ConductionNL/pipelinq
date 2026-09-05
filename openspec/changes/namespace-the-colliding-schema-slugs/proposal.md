# Namespace the colliding schema slugs

## Why

A schema slug is global per organisation and `SchemaMapper::find()` matches
`LOWER(slug)`, so when two apps declare one slug the lookup answers with
whichever row it reached first. A fleet audit on 2026-09-05 found eighteen
slugs claimed by more than one app. Two of them are this app's.

## What decides namespace versus consolidate

Comparing the declared property sets. A pair that shares most of its fields is
one record described twice and should be folded onto one owner; a pair that
shares almost nothing is two records that reached for the same word.

- `cashCount` — this app's POS drawer count at shift close, against shillinq's
  kasadministratie Z-report. **Zero** shared fields.
- `conversation` — this app's WhatsApp/SMS channel thread, against hermiq's
  agent chat thread. **Zero** shared fields.

Both are renamed apart. `timeEntry` went the other way in
`the-billing-time-entry-is-a-satellite`, and it is the calibration point: it
shared 9 of 64 fields and folding it took five new fields on the owner plus a
migration. Folding a pair that shares nothing would be that work with no record
to show for it.

## What changes

`cashCount` becomes `posCashCount`, matching this app's other POS schemas
(`posTransaction`, `posRole`, `posStaff`). `conversation` becomes
`channelConversation`, naming the thing that distinguishes it: the channel.

The app-config keys do not move. They are live persisted state, and the same
pin already exists for `klantLoyaltyAccount_schema`.
