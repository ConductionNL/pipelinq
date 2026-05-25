# Design: Pipelinq IA Alignment

## Target IA topology

The post-refactor left-nav looks like this (top-menu → sub-pages):

```
Mijn werk
  ├── Dashboard                       (route: /,            id: Dashboard)
  └── Werkvoorraad                    (route: /my-work,     id: MyWork)

Contacten
  ├── Lijst                           (route: /clients,     id: Clients)
  ├── Contactpersonen                 (route: /contacts,    id: Contacts)
  └── Synchronisatie                  (route: /sync-settings, id: SyncSettings)

Pipeline
  ├── Kanban                          (route: /pipeline,    id: Pipeline)
  ├── Leads                           (route: /leads,       id: Leads)
  └── Prospects                       (route: /prospects,   id: Prospects)  ← NEW

Klachten & Verzoeken
  ├── Verzoeken                       (route: /requests,    id: Requests)
  └── Klachten                        (route: /complaints,  id: Complaints)

Catalogus
  └── Producten & diensten            (route: /products,    id: Products)

Beheer (Nextcloud admin settings panel — unchanged shell)
  ├── Algemeen                        (admin-settings spec)
  └── Observability  (future)         (prometheus-metrics SETTING surface)
```

The Settings section (in-app, lower-left) keeps its current entries
(Pipelines, Forms, Automations, Features & roadmap) — they are not in
the audited spec list.

The top-menus **Tasks**, **Contactmomenten**, **Surveys**, **Queues**,
**Kennisbank**, and **Rapportage** are out of scope for this change
(their owning specs are not in the audited IA input). They remain as
top-level menu entries for now and may be re-grouped in a follow-up
audit when those specs land on the IA input.

## Why parent menus instead of separate routes per spec

- The current 15-entry flat menu does not scan well — users have to
  read every entry to find a relationship feature vs. a pipeline
  feature vs. a klacht workflow.
- Grouping by domain (klant / pipeline / klacht / catalogus /
  mijn werk / beheer) gives a 6-row top menu that mirrors how the
  product is talked about in stakeholder conversations and tender
  requirements.
- Sub-pages are first-class routes (each gets its own URL, breadcrumb,
  and direct link), so deep-linking from notifications, dashboard
  widgets, and external systems continues to work without redirects.

## Backwards-compat: keep route paths stable

- All existing route paths under `/clients`, `/leads`, `/pipeline`,
  `/requests`, `/complaints`, `/products`, `/my-work`, and
  `/sync-settings` are **preserved**. Only the `menu` structure in
  `src/manifest.json` is rewritten.
- Existing bookmarks, deep-links from notifications, and dashboard
  widget routes (e.g. `#/leads/{objectId}`) keep working.
- The new `/prospects` route is the only fresh path introduced.

## Manifest schema fit

The manifest schema (`@conduction/nextcloud-vue` app-manifest-v2) uses
flat `menu[]` entries with `section` (`"primary"` default, `"settings"`
for the lower group) and `order`. Parent/child grouping is expressed
either by:

- **a `parent` field on child entries pointing to the parent menu id**, or
- **a `children: []` array on the parent**,

depending on the lib's current grouping primitive. The implementing
PR MUST verify which form `@conduction/nextcloud-vue` consumes — if
neither is supported yet, file a lib gap and use the closest available
visual hierarchy (e.g. ordered groups by `order` ranges) as an interim.

## Prospects sub-page (new)

`prospect-discovery` currently has only a dashboard widget
(`ProspectWidget.vue`) plus admin ICP config under
`views/settings/ProspectSettings.vue`. The IA wants a full-page
"Prospects" sub-page under Pipeline that shows discovered prospects
with their fit scores, drill-down, and convert-to-lead actions.

- **Route:** `/prospects`
- **Type:** `custom` page rendering a new `ProspectsView.vue` (lib gap
  — no declarative type covers a scored prospect list with
  external-source enrichment). The existing widget logic in
  `ProspectWidget.vue` is the starting point; the new view is the
  full-page expansion of it.
- The admin ICP config (`ProspectSettings.vue`) stays where it is —
  the IA places admin configuration of prospect discovery under
  Beheer, separate from the user-facing Prospects browsing page.

## Catalogus merge

`product-catalog` and `product-service-catalog` are explicitly merged
per the IA rationale ("Merged with `product-catalog` — single
canonical list"). The merge resolution:

- The existing `Products` page (`/products`, schema `product`) is the
  canonical list under `Catalogus → Producten & diensten`.
- The `product-service-catalog` spec describes a richer
  IPDC/UPL-compliant `pdc` register. The two specs do not conflict on
  IA placement (both want the same single page); the data-model
  reconciliation is tracked separately and is **not** part of this
  IA-alignment change.
- This change covers only the menu placement. The data-model merge
  remains a separate spec change.

## Out-of-scope (explicit non-changes)

- No Vue component file renames or moves.
- No route path changes (only menu structure).
- No backend / schema / API contract changes.
- No changes to the Settings section (Pipelines, Forms, Automations,
  Features & roadmap).
- No changes to Tasks, Contactmomenten, Surveys, Queues, Kennisbank,
  Rapportage menu entries (not in audited input).
- The Observability pane for prometheus-metrics is a future additive
  change, not a relocation.
