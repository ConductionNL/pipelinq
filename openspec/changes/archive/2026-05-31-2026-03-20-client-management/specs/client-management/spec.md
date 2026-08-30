# Delta Spec: client-management enhancements

## Changes to specs/client-management/spec.md

### Updated Implementation Status

The following items have been newly implemented:

- **Summary statistics panel** on ClientDetail.vue: Shows open leads count + value, won leads count + value, open requests count, and total value with EUR formatting. Values computed from already-fetched related entities.
- **Dynamic @type mapping** in ClientForm.vue: Sets `@type` to `schema:Organization` for organization clients and `schema:Person` for person clients on save.

### Corrections to Previous Assessment

The following items were previously listed as "NOT implemented" but were already present:
- **Contact detail write-back sync on save**: Already implemented in `ContactDetail.vue` via `syncToContacts()` method (same pattern as ClientDetail).
- **Contact detail sync status badge**: Already showing "Synced with Contacts" badge when `contactData.contactsUid` is set.
