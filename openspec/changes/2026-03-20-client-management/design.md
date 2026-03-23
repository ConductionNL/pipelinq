# Design: client-management enhancements

## Architecture Overview

All changes are frontend-only. Data operations use the existing generic object store (`useObjectStore`) which calls OpenRegister's API. Contact sync uses the existing `/api/contacts-sync/write-back` endpoint.

## Key Design Decisions

### 1. Summary Statistics Card

**Decision**: Add a `CnDetailCard` with computed summary stats calculated from the already-fetched `leads` and `requests` arrays in `ClientDetail.vue`.

**Rationale**: The detail view already fetches linked leads and requests. Computing counts/sums from these arrays avoids additional API calls. The stats card appears between Client Information and Contacts sections.

**Fields displayed**:
- Open leads: count and total value
- Won leads: count and total value
- Open requests: count
- Total value: open + won leads
- Client since: creation date from object metadata

### 2. Contact Detail Sync

**Decision**: Replicate the `syncToContacts()` pattern from `ClientDetail.vue` into `ContactDetail.vue`.

**Rationale**: The backend `/api/contacts-sync/write-back` endpoint already supports both `objectType=client` and `objectType=contact`. Only the frontend trigger is missing.

### 3. Dynamic @type

**Decision**: Set `@type` in the form data based on the selected `type` field: `person` -> `schema:Person`, `organization` -> `schema:Organization`.

**Rationale**: OpenRegister uses `@type` for Schema.org compliance. The register schema defaults `@type` to `schema:Person` but this should vary by client type.
