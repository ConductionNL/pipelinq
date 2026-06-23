# Proposal: Client / Contact 360 detail → declarative type:detail

## Why

The Client 360 (`ClientDetail.vue`, ~1151 lines) and Contact (`ContactDetail.vue`,
~591 lines) views were the last two large host-app `type:"custom"` page-host
components in the clients/contacts area. Both were kept-with-reason because a
declarative `type:"detail"` page could not, at the time, drive:

- the cross-schema KPI chips (open/won lead counts + values, open requests),
- the parallel related-object list sections (contacts / leads / requests /
  projecten / contactmomenten / complaints, all FK `client`),
- the contact→organisation linking flow, and
- the rich in-body sub-features (Relationships, Activity, Communication History,
  Bookings, the contactmoment quick-log, the BSN/BRP panel).

`@conduction/nextcloud-vue` (`feat/dashboard-embeddable`) has since shipped the
full declarative detail contract — `summaryAggregates`, `relatedCollections`,
`relationLinks`, and crucially **`bodyWidgets`**: declarative IN-BODY sections
that render a registered host-app component as a titled body card (not a sidebar
tab), with object context resolved via `@objectId` / `@object.<field>` tokens
and also provided on `cnSectionContext`. This lets every rich sub-feature stay
in the page body — nothing shifts to the sidebar, nothing is dropped — while the
two monolithic page-host views are deleted.

## What Changes

- **Converts** `ClientDetail` and `ContactDetail` from `type:"custom"` (host
  `.vue`) to declarative `type:"detail"` manifest pages:
  - identity / account / contact-person fields auto-render in the body via the
    default `CnObjectDataWidget` (name/email/phone stay read-only via the index
    `fieldOverrides` already in place),
  - **ClientDetail** `summaryAggregates` — open leads count + value, won leads
    count + value, new requests count,
  - **ClientDetail** `relatedCollections` — contacts / leads / requests /
    projecten / contactmomenten / complaints (FK `client`), each with row-nav,
  - **ContactDetail** `relationLinks` — link/relink the parent organisation
    (`fkField:"client"`, `labelField:"name"`), replacing the bespoke
    `CnFormDialog` organisation linker,
  - **`bodyWidgets`** host each rich sub-feature in the page body:
    - ClientDetail: ContactRelationships, ActivityTimeline, CommunicationHistory,
      BookingsCard (`after-related`); ContactmomentQuickLog (`end`),
    - ContactDetail: BrpContactPanel (`after-data`); ContactRelationships,
      CommunicationHistory (`after-related`).
- **Registers** the six existing sub-feature components as `kind:"section"`
  registry entries (they were imported only inside the deleted views).
- **Deletes** `src/views/clients/ClientDetail.vue` and
  `src/views/contacts/ContactDetail.vue` and their `registry` `kind:page`
  entries. The sub-feature components STAY (now registered as sections).

## Kept-as-note (no declarative primitive)

- The **"Edit in Contacts" deep-link** and the **delete-with-linked-entity
  warning** dialog have no declarative primitive. The page Delete uses
  `CnDetailPage`'s standard delete; identity remains editable from the linked
  Nextcloud contact (the read-only mirror contract is unchanged).
- `summaryAggregates` filters are **equality-only**, so "Open leads" maps to
  `status:open` (the lead enum is `open|won|lost`) and "Open requests" maps to a
  single "New requests" chip on `status:new` (`in_progress` cannot be combined
  into one equality chip).
- The **contactmoment quick-log** no longer auto-refreshes the related list on
  save (no parent host to re-fetch); the page Refresh action re-runs the
  sections. Likewise **BrpContactPanel**'s `@contact-updated` re-fetch is
  replaced by the page Refresh action.

## Impact

- Affected manifest pages: `ClientDetail`, `ContactDetail`.
- Affected code: `src/registry.js` (six `kind:"section"` entries replace two
  `kind:page` entries; imports moved), two deleted view files.
- No data-model, API, or feature-contract change — same registers/schemas, same
  related data, same routes.
