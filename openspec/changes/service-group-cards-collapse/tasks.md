# Tasks: service-group-cards-collapse

## 1. Add ServiceHub page to manifest.json

- [ ] Add a new page entry `ServiceHub` with `route: "/service"`, `type: "card-grid"` (or
  `"custom"` using `ServiceHubView` if `card-grid` is not yet a supported declarative
  type), and `title: "Service"` to the `pages` array in `src/manifest.json`.
- [ ] Configure the card list on the ServiceHub page to reference leaf ids: `Requests`,
  `Tasks`, `Contactmomenten`, `Complaints`, `Projects`, `MyWork`, `BookingsGroup`,
  `Queues` (each card label, icon, and target route sourced from the existing menu
  entries).

## 2. Update the Service menu entry in manifest.json

- [ ] Change the `Service` menu entry (id `Service`) to add a `route: "ServiceHub"`
  field so it becomes a direct clickable link rather than an expandable parent group.
- [ ] Verify there are no `children` or nested arrays attached to the `Service` entry
  after the change — it must be a leaf menu item, not a group.

## 3. Update menu-layout.json relocations

- [ ] Remove the eight relocation entries that move leaves into `Service`
  (`Requests`, `Tasks`, `Contactmomenten`, `Complaints`, `Projects`, `MyWork`,
  `BookingsGroup`, `Queues`) from `src/menu-layout.json`, as these leaves are no longer
  nav children — they are now rendered as cards on the ServiceHub page.
- [ ] If the `applyMenuRelocations` logic in `main.js` needs a new directive to register
  the card list on ServiceHub, document the required config key.

## 4. Implement ServiceHubView component (if card-grid type unavailable)

- [ ] If the `app-manifest-v2` schema does not yet support a `card-grid` page type,
  create `src/views/ServiceHubView.vue` as a custom page component that renders one
  `NcAppNavigationItem`-backed card per Service leaf using the nc-vue `CnCardGrid`
  component (or equivalent).
- [ ] Register `ServiceHubView` in `src/registry.js` / `src/App.vue` router under route
  `/service`.

## 5. Verify all former leaf routes remain reachable (REQ-NAV-003)

- [ ] Confirm each of the 17 former Service leaf routes listed in REQ-NAV-003 is still
  present in the `pages` array of `src/manifest.json` (or the relevant `manifest.d`
  fragment) after the change.
- [ ] Run `openspec validate` to confirm no pages referenced in the spec are missing.

## 6. Add / update e2e scenario coverage

- [ ] Add a Playwright scenario covering REQ-NAV-001: navigate to pipelinq, confirm
  "Service" appears as a single top-level item with no sub-menu.
- [ ] Add a Playwright scenario covering REQ-NAV-002: click "Service", confirm all eight
  cards render on the ServiceHub page.
- [ ] Add a Playwright scenario covering REQ-NAV-003: navigate directly to `/requests`,
  `/tasks`, `/complaints`, `/projects`, `/my-work`, `/queues` by URL and confirm each
  page loads.
- [ ] Tag all new scenarios `@e2e` (not `@e2e exclude`) so gate-19 picks them up.

## 7. Bump app version

- [ ] Increment `<version>` in `appinfo/info.xml` to bust the NC immutable bundle cache
  after the frontend change ships.
