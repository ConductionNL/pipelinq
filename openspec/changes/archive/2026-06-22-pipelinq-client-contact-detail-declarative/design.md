# Design: Client / Contact 360 detail → declarative type:detail

## The bodyWidgets approach

The crux of this conversion is `bodyWidgets` (nc-vue `feat/dashboard-embeddable`,
commit "feat(detail-page): declarative in-body sections primitive"). Unlike the
integration registry — which forces a sidebar **tab + widget parity pair** — a
body section resolves its component straight from the v2 component registry
(`cnRegistry`) or the legacy `cnCustomComponents` map (the same resolver
`CnPageRenderer` uses for `type:"custom"` pages). So a rich sub-feature can sit
IN THE PAGE BODY exactly where it sat in the old host view, with no sidebar shift.

Each `bodyWidgets[]` entry is `{ id, component, title?, props?, placement?,
colSpan? }`:

- `component` is a registry name resolved to a `kind:"section"` (or any entry
  exposing a `.component`).
- `props` values are token-resolved against the page's object context:
  `@objectId` → the route `:id`, `@object.<field>` → a field on the loaded
  object. An unresolved `@`-token is dropped (the child sees `undefined`).
- `placement` selects one of four mount points in the body —
  `before-body` | `after-data` | `after-related` | `end` (default `end`).
- The loaded object + objectId are also `provide`d on `cnSectionContext`, so a
  section component can `inject` context instead of taking props. Our six
  components already take explicit props, so we pass props.

`CnDetailPage` mounts one `CnBodySections` per placement slot and filters
`bodyWidgets` by `placement`, so a section lands precisely where chosen.

## Per-section mapping

### ClientDetail (`type:"detail"`, register `pipelinq`, schema `client`)

| Old section (ClientDetail.vue)            | Declarative mapping |
|-------------------------------------------|---------------------|
| Identity card (read-only NC-contact mirror) | auto body `CnObjectDataWidget` (name/email/phone read-only via index `fieldOverrides`) |
| Account & relationship card               | auto body `CnObjectDataWidget` |
| Summary KPIs (open/won leads count+€, open requests) | `summaryAggregates` — 5 chips (see below) |
| Contacts list                             | `relatedCollections[0]` schema `contact`, FK `client`, rowRoute `ContactDetail` |
| Leads list                                | `relatedCollections[1]` schema `lead`, sort `updatedAt desc`, rowRoute `LeadDetail` |
| Requests list                             | `relatedCollections[2]` schema `request`, sort `requestedAt desc`, rowRoute `RequestDetail` |
| Projecten list                            | `relatedCollections[3]` schema `project`, sort `_dateCreated desc`, rowRoute `ProjectDetail` |
| Contactmomenten list                      | `relatedCollections[4]` schema `contactmoment`, sort `contactedAt desc`, rowRoute `ContactmomentDetail` |
| Complaints list                           | `relatedCollections[5]` schema `complaint`, sort `_dateCreated desc`, rowRoute `ComplaintDetail` |
| `<ContactRelationships>` card             | `bodyWidgets` section `relationships` (`after-related`), props `{entityId:@objectId, entityType:"client", entityName:@object.name}` |
| `<ActivityTimeline>` card                 | `bodyWidgets` section `activity` (`after-related`), props `{entityId:@objectId, entityType:"client"}` |
| `<CommunicationHistory>`                  | `bodyWidgets` section `communication-history` (`after-related`), props `{entityId:@objectId, entityType:"client"}` |
| `<BookingsCard>`                          | `bodyWidgets` section `bookings` (`after-related`), props `{customerId:@objectId}` |
| Contactmoment quick-log (NcDialog)        | `bodyWidgets` section `contactmoment-quick-log` (`end`), props `{clientId:@objectId, inline:true}` |

`summaryAggregates` (equality filters scoped via `@objectId`):

```
Open leads        count  lead   {client:@objectId, status:open}
Open leads value  sum    lead   {client:@objectId, status:open}  field value  currency
Won leads         count  lead   {client:@objectId, status:won}
Won leads value   sum    lead   {client:@objectId, status:won}   field value  currency
New requests      count  request {client:@objectId, status:new}
```

### ContactDetail (`type:"detail"`, register `pipelinq`, schema `contact`)

| Old section (ContactDetail.vue)           | Declarative mapping |
|-------------------------------------------|---------------------|
| Contact person card (role/email/phone/client) | auto body `CnObjectDataWidget` |
| Parent Organisation card + linker dialog  | `relationLinks[0]` `{register:pipelinq, schema:client, fkField:client, labelField:name}` — the search-and-link modal replaces the bespoke `CnFormDialog`; the linked client shows in the data widget's `client` field |
| `<BrpContactPanel>`                        | `bodyWidgets` section `brp` (`after-data`), props `{contactId:@objectId}` |
| `<ContactRelationships>` card             | `bodyWidgets` section `relationships` (`after-related`), props `{entityId:@objectId, entityType:"contact", entityName:@object.name}` |
| `<CommunicationHistory>`                  | `bodyWidgets` section `communication-history` (`after-related`), props `{entityId:@objectId, entityType:"contact"}` |

## Registry: kind:"section"

The six sub-feature components were imported only inside the two deleted views.
They are now imported in `registry.js` and registered as `kind:"section"`
(recognised by `CnAppRoot`'s registry validator with no required metadata —
`REGISTRY_KIND_REQUIRED_FIELDS.section = []`). The two `kind:page` entries for
`ClientDetail` / `ContactDetail` are removed.

## Kept-as-note (judgment calls)

1. **"Edit in Contacts" deep-link** — opened the Nextcloud Contacts app for the
   linked `contactsUid`. No declarative primitive expresses an external
   deep-link header action. Dropped from the page; identity stays editable from
   the linked NC contact (the read-only-mirror contract is unchanged). Recorded
   in the page `_note`.
2. **Delete-with-linked-entity warning** — the bespoke dialog enumerated linked
   contacts/leads/requests/complaints before deleting. No declarative primitive;
   the page uses `CnDetailPage`'s standard delete. Recorded in the `_note`.
3. **`summaryAggregates` equality-only** — "Open leads" = `status:open`;
   "Open requests" = a single "New requests" chip (`status:new`). The
   `in_progress` request state cannot join `new` in one equality chip.
4. **No auto-refresh on quick-log save / BRP update** — there is no host parent
   to re-fetch; the page Refresh action re-runs sections.
5. **Parent-org type line dropped** — the old card showed client name + type as
   a clickable deep-link; the declarative client FK shows id/name in the data
   widget and `relationLinks` drives link/relink. The separate type line is
   dropped.

All five are recorded in the manifest page `_note`s so nothing is silently lost.
