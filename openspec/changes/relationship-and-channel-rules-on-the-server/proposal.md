---
kind: code
---

## Why

Two data rules lived in the browser, so only the detail page applied them:

- **Relationship inverse rows.** `ContactRelationships.vue` wrote the inverse row as a second client save. Editing a relationship saved through the store's full PUT with eight fields, which wiped `fromContact`, `toContact`, `fromType` and `toType` on the row and its inverse (PL-ABSTRACT audit bug 1). A save that failed halfway left a one-way relationship, and the API and imports never got an inverse at all.
- **The single `email`/`phone` fields.** `ContactChannelsSection.vue` recomputed them from the primary entry of `emails[]`/`phones[]`. Any other writer left them stale.

Ruben accepted moving both into server listeners (PL-ABSTRACT Q3, 21 Oct 2026), so the screens can become plain manifest config.

## What changes

- `RelationshipInverseListener` (post-save, deferred per ADR-078) creates the inverse row on create, patches only the fields that differ on update (a type change moves the inverse instead of adding one), and deletes the inverse on a purge or a soft delete.
- `ContactChannelMirrorListener` (pre-save) sets `email`/`phone` from the primary entry, or the first entry, of a list that changed. A writer that changes only `email` keeps working.
- The detail page saves only the relationship itself, edits with PATCH, and no longer mirrors the channel fields.

## Impact

- No schema change. Existing one-way pairs gain their inverse on their next edit.
- Specs: `contact-relationship-mapping`, `contact-channel-details`.
