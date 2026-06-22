# Tasks

## 1. Registry

- [x] 1.1 Import the six sub-feature components into `src/registry.js`
  (ContactRelationships, ActivityTimeline, CommunicationHistory, BookingsCard,
  ContactmomentQuickLog, BrpContactPanel).
- [x] 1.2 Register each as `kind:"section"`.
- [x] 1.3 Remove the `ClientDetail` and `ContactDetail` `kind:page` registry
  entries and their imports.

## 2. ClientDetail manifest page

- [x] 2.1 Change `type:"custom"` → `type:"detail"`, drop `component`.
- [x] 2.2 Add `summaryAggregates` (open/won lead count+value, new requests).
- [x] 2.3 Add `relatedCollections` (contacts/leads/requests/projecten/
  contactmomenten/complaints, FK `client`, with rowRoute).
- [x] 2.4 Add `bodyWidgets` (relationships/activity/communication-history/
  bookings `after-related`; contactmoment quick-log `end`).
- [x] 2.5 Keep the integration `sidebar.tabs`.

## 3. ContactDetail manifest page

- [x] 3.1 Change `type:"custom"` → `type:"detail"`, drop `component`.
- [x] 3.2 Add `relationLinks` (parent organisation, fkField `client`).
- [x] 3.3 Add `bodyWidgets` (BRP `after-data`; relationships +
  communication-history `after-related`).

## 4. Delete monolithic views

- [x] 4.1 Delete `src/views/clients/ClientDetail.vue`.
- [x] 4.2 Delete `src/views/contacts/ContactDetail.vue`.
- [x] 4.3 Record kept-as-note items in the manifest page `_note`s.

## 5. Verify

- [x] 5.1 `npm run build` green; manifest schema check passes.
- [x] 5.2 Vitest ≥ baseline (32 pass; pre-existing recurringRevenue orphan
  ignored).
- [x] 5.3 Lint clean on changed files.
- [x] 5.4 Live (`:8080`): client + contact detail render — chips populate,
  related-collection sections render with working row-nav, each in-body section
  reads the right object context, 0 NEW console errors. Screenshots captured.
