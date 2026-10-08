# Tasks: Pipelinq IA alignment, shrunk to the Prospects page

> Delta fix-up 2026-10-07: the menu restructure (old tasks 1 to 18 and 24 to 27) was superseded by the nav in `src/menu-layout.json` and is dropped from this change. Old task 22 (`src/customComponents.js`) does not apply: that file no longer exists and `src/registry.js` is the one registration surface. The task numbers below are kept because `ProspectsView.vue` cites `#task-20`.

## 2. Prospects page

- [x] 19. Page and menu entry in `src/manifest.d/45-prospects.json` (`id: Prospects`, `route: /prospects`, `type: custom`, `component: ProspectsView`)
- [x] 20. `src/views/prospects/ProspectsView.vue`: sortable, paged prospect table over `store/modules/prospect.js`, Refresh, and an "Add as client" row action
- [x] 21. `ProspectsView` registered in `src/registry.js`
- [x] 23. Menu placement: `Prospects` relocated into the Sales (`Dashboard`) group in `src/menu-layout.json`, and a Sales card in `src/manifest.d/98-modules.json`
