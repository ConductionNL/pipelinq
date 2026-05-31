# Design: client-management enhancements

status: pr-created

## Architecture Overview

All changes are frontend-only. No new schemas, registers, or API routes are introduced. Data
operations use the existing generic object store (`useObjectStore`) which calls OpenRegister's
REST API. Contact sync uses the existing `/api/contacts-sync/write-back` endpoint already
implemented in `ContactSyncController.php`.

## Key Design Decisions

### 1. Summary Statistics Card

**Decision**: Add a `CnDetailCard` with computed summary stats calculated from the
already-fetched `leads` and `requests` arrays inside `ClientDetail.vue`.

**Rationale**: The detail view already fetches linked leads (filtered by `client` UUID) and
linked requests. Computing counts/sums client-side from these arrays avoids additional API calls
and keeps the component self-contained. The stats card is inserted between the Client Information
card and the Contacts section.

**Fields displayed**:
| Stat | Source |
|------|--------|
| Open leads count | `leads.filter(l => l.status !== 'won' && l.status !== 'lost').length` |
| Open leads value (EUR) | sum of `lead.value` for open leads |
| Won leads count | `leads.filter(l => l.status === 'won').length` |
| Won leads value (EUR) | sum of `lead.value` for won leads |
| Open requests count | `requests.filter(r => r.status !== 'closed').length` |
| Total pipeline value | open value + won value |
| Client since | `clientObject.createdAt` (OpenRegister built-in) |

Currency formatting uses `t(appName, '{value} EUR')` with `Intl.NumberFormat` for locale-aware
display; no hardcoded strings.

### 2. Contact Detail Sync

**Decision**: Replicate the `syncToContacts()` method from `ClientDetail.vue` into
`ContactDetail.vue`.

**Rationale**: The backend `/api/contacts-sync/write-back` endpoint already accepts
`objectType=contact` and `objectType=client`. Only the frontend trigger is absent for contacts.
Reusing the identical POST pattern keeps both components consistent and the backend unchanged.

**Sync flow**:
1. User saves contact → `ContactDetail.vue` calls `saveObject()`
2. On success, `syncToContacts()` POSTs `{ objectType: 'contact', objectId }` to
   `/api/contacts-sync/write-back`
3. Sync failure is caught, logged via `console.error`, and does NOT block the save confirmation
4. `contactsUid` is updated on the store object from the API response if returned

### 3. Contact Sync Status Badge

**Decision**: Render a `CnStatusBadge` (or equivalent badge element from
`@conduction/nextcloud-vue`) on `ContactDetail.vue` when `contactData.contactsUid` is truthy.

**Rationale**: `ClientDetail.vue` already shows this badge; applying the same logic to
`ContactDetail.vue` makes the two detail views consistent. No new component is needed — the
existing badge pattern is reused directly.

### 4. Dynamic @type

**Decision**: In `ClientForm.vue`, maintain a `typeMapping` constant and derive `@type` from
the form's `type` field whenever it changes.

```js
const TYPE_MAPPING = {
  person: 'schema:Person',
  organization: 'schema:Organization',
}
// In computed formData or watcher:
'@type': TYPE_MAPPING[this.form.type] ?? 'schema:Person'
```

**Rationale**: OpenRegister uses `@type` for Schema.org JSON-LD compliance (ADR-001, ADR-011).
The register schema defaults `@type` to `schema:Person`; dynamic assignment on the frontend
ensures the stored object always reflects the correct Schema.org type without schema migration.

## Reuse Analysis

This change is frontend-only. No new PHP services, controllers, or OpenRegister schemas are
introduced. Existing platform capabilities reused:

| Capability | Provided by | How used |
|------------|-------------|----------|
| Object CRUD | `useObjectStore` / `ObjectService` | Saves client and contact objects; no custom API calls |
| Contacts write-back | `ContactSyncController` + `ContactVcardService` | Existing `POST /api/contacts-sync/write-back` endpoint; no backend changes |
| Sync status badge | `CnStatusBadge` from `@conduction/nextcloud-vue` | Reused from `ClientDetail.vue` pattern |
| Stats display | `CnDetailCard` + `CnDetailGrid` from `@conduction/nextcloud-vue` | Standard label-value grid for stats panel |
| Leads/requests data | Already fetched by `ClientDetail.vue` via `fetchUsed` | No extra API calls for stats card |

No new PHP code is required. No new Pinia stores. No new Vue components beyond inline additions
to existing `.vue` files.

## Seed Data

**Not applicable** — this change modifies only frontend Vue components and introduces no new
schemas or registers. Per company ADR-001 (data layer), seed data is only required when a
change introduces or modifies OpenRegister schemas.
