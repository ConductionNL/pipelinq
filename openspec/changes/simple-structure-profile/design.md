# Design: simple-structure-profile

## D-1 One manifest, two layout files

`src/manifest.json` and `src/manifest.d/*.json` stay the single source of pages
and menu entries. `src/menu-layout.json` (full) is not edited.
`src/menu-layout.simple.json` (simple) sits next to it.

`src/utils/structureProfile.js` is the dossiq module, copied.
`buildProfiledManifest` passes the standard layout keys to the library's
`buildManifest` and applies two profile keys around it: `menu` (entries merged
before the manifest's, first definition wins) and `pages` (overlays by page id).

## D-2 The setting

`menu_structure` holds `simple` or `full`. Unset, empty or mistyped reads as
`simple`. `lib/Service/Settings/MenuStructure.php` owns the reading.
`DashboardController::page()` provides it as initial state, so the first render
is right. `SettingsService::CONFIG_KEYS` lists the key, so the admin write
stores it.

## D-3 Modules

A module is declared in the simple file:

```json
"modules": {
  "sales": {
    "label": "Sales",
    "ids": ["Dashboard", "Leads", "Prospects", "Pipeline"],
    "menu": [{ "id": "Dashboard", "label": "Sales overview", "order": 110 }]
  }
}
```

`ids` are the manifest entries that leave the menu while the module is off.
`menu` joins the profile menu when it is on. `src/utils/menuModules.js`
(`applyMenuModules`) rewrites the profile file before it is built: off adds the
ids to `removals`, on takes them out and adds the `menu` entries and one
caption, "Modules".

`menu_modules` is a comma separated list of module keys. A word that is not a
module is dropped. The six keys: `sales`, `marketing`, `pos`, `products`,
`contracts`, `loyalty`.

Why a second setting and not six: one key holds the whole choice, one write
stores it, and `occ config:app:set pipelinq menu_modules --value=sales,pos`
is something an operator can type.

Switched on, Marketing keeps its group (15 entries). The other modules add flat
entries. So the default simple menu is flat, and a menu with Marketing on has
one group.

Modules only apply to the simple structure. The full structure shows
everything, as before.

## D-4 The Modules page

`src/manifest.d/98-modules.json` adds one page, `Modules`, at `/modules`. It is
a `custom` page. Its component, `src/views/ModulesPage.vue`, draws the page
config as link cards: a label, a line and a route per card, grouped by
category. It draws them itself because the library does not export its card
page (`CnReportsPage` is in the library's source and not in its entry). One category per module, plus "Also in
Pipelinq" for Tasks, Contact persons and the Operational overview.

It is not a second `type: "reports"` page. ADR-112 allows one per app and
gate-104 fails on two, and these cards are not reports.

The page is in the manifest, so it exists in both structures and its address is
stable. Only the simple menu links to it (`ModulesMenu`, in the footer). The
full menu is asserted unchanged, so it gets no entry.

This is the smallest thing that keeps every page reachable: one small view, no new endpoint, and the no-loss rule can be checked by reading
two JSON files.

## D-4b Rapportages sits in the menu, not in the footer

ADR-112 Decision 3 puts the Reports entry in the footer, and the full structure
keeps it there. The Zuiddrecht design puts Rapportages under Meer in the main
menu, and Ruben approved that menu. The simple structure follows the design.
Gate-104 reads `src/menu-layout.json` only, so it does not see this. It is a
deliberate difference between the two structures, named here so it is not found
later as drift.

## D-5 The start page

The simple file names it: `"home": { "page": "KccWerkplek", "rootMovesTo": "/sales-overview" }`.
`applyHomePage` moves the page that owns `/` to `rootMovesTo` in the built
manifest and returns the home page id. `main.js` adds a route that redirects
`/` to it. The moved page keeps its id, so every link by route name still opens
it, and it has an address that survives a reload.

In the simple structure a bookmark of `/` now opens the contact centre
dashboard. That is the point. The full structure has no `home` and keeps `/`.

## D-5b The getting-started tour

The manifest's one tour is a sales journey. It starts on a first visit and
tells the reader to click Products, Contacts, Leads and Contracts in the menu.
The simple menu has none of those, so the tour would stop at its second step.

`holdUnreachableTours` takes a tour out of the BUILT manifest when it points at
a menu entry the built menu lacks. The manifest keeps the tour, and the full
structure starts it as before. A tour for the contact centre does not exist
yet; the simple structure has none.

## D-6 Views, not pages

Contactmomenten and Organisaties are menu entries with a `query`
(`ticketType=interaction`, `type=organization`). Both values are enum members
of the seeded schema, so they are the same on every instance. The index page
reads filters from the address.

## D-7 The no-loss rule

Gate-53 reads `src/menu-layout.json` only. The rule for the simple file is held
in `tests/vitest/structureProfile.spec.js`: every entry of the full menu is in
the simple menu, footer or settings, or opens the same route, or a page the
simple menu opens has a card for its route.

## D-8 e2e

The CI instance is seeded on `full`, so the existing suite keeps walking the
full menu. The simple menu has its own spec. pipelinq's suite runs six workers
on one instance, so that spec does not flip the setting, which is what dossiq's
does on its one worker. It rewrites the initial state on its own page. The
server half (setting to initial state) is covered by `MenuStructureTest`.
