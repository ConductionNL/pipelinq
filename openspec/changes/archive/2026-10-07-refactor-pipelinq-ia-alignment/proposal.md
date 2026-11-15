# Proposal: Pipelinq IA alignment, shrunk to the Prospects page

## Why

This change first proposed a six-group Dutch menu (Mijn werk, Contacten, Pipeline, Klachten & Verzoeken, Catalogus, Beheer) over a flat fifteen-entry nav. By the time it was built that nav no longer existed: the menu already grouped entries through `children` arrays and `src/menu-layout.json`, with two dashboards and around forty entries. Applying the Dutch groups would have undone that, so the menu restructure was dropped.

One deliverable still held: prospect discovery had a dashboard widget, admin settings and the `prospect#index` API, but no page of its own. That page was built, and this change now specifies only that page.

## What changes

- A Prospects page at `/prospects` lists the discovered prospects in a sortable, paged table with a fit score, company, industry, employees and location, and an "Add as client" action per row.
- The page has a menu entry, relocated into the Sales group by `src/menu-layout.json`, and a card in the Sales category of the Modules page.

## Dropped from this change

The menu restructure (old tasks 1 to 18 and 24 to 27): the six Dutch groups, the relabels, the sync-settings menu entry, the backwards-compatibility walk over the old routes and the translation and README updates. The current nav is owned by `src/menu-layout.json`; a new IA audit against it would be its own change.

## Impact

- Frontend only: `src/manifest.d/45-prospects.json`, `src/views/prospects/ProspectsView.vue`, `src/registry.js`, `src/menu-layout.json`, `src/manifest.d/98-modules.json`.
- No schema or backend change. The page reads the existing prospect store and API.
