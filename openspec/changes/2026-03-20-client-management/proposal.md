# Proposal: client-management enhancements

## Problem

The client management spec identifies several MVP gaps that remain unimplemented:
1. No summary statistics panel on client detail view (open leads count/value, won leads count/value, open requests count)
2. Contact person detail view does not trigger write-back sync to Nextcloud Contacts on save
3. Contact person detail view does not show "Synced with Contacts" badge
4. Client `@type` is not dynamically set based on `type` field (always defaults to `schema:Person`)

## Proposed Change

Implement the missing MVP features:
- Add a summary statistics card to `ClientDetail.vue` showing aggregated lead/request counts and values
- Add write-back sync to `ContactDetail.vue` on save (same pattern as ClientDetail)
- Add sync status badge to `ContactDetail.vue`
- Set `@type` dynamically in `ClientForm.vue` based on selected type

### Out of Scope
- Duplicate detection (V1)
- Import/export CSV/vCard (V1)
- KVK integration in client form (V1)
- BSN handling (Enterprise)
- Client hierarchy (V1)
- Client segmentation/tagging (V1)
- Health scoring (Enterprise)
- GDPR data subject rights (V1)

## Impact

- **Files modified**: 2-3 Vue files
- **New files**: 0
- **Risk**: Low -- enhancing existing frontend components with additional sections
