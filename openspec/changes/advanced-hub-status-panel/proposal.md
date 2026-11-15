---
kind: code
screens: [PqGeavanceerdHub]
---

## Why

Board PqGeavanceerdHub puts a status pill on every card of the Advanced page, such as "7 actief, 2 gepauzeerd" on
Flows or "1 koppeling heeft een fout" on Integrations. It closes with a panel for administrators that shows how the
menu is set: the profile, and which modules are in or out of the menu.

## What changes

- The Advanced page's cards declare a `status`. Counts come from OpenRegister: flows by their enabled flag,
  pipelines and their stages, services and resources, connections in error, and export runs that failed since
  midnight. The page closes with a `panel` holding the menu structure (nextcloud-vue 2.158, CnLinkCardsPage).
- At boot, `src/utils/menuRuntime.js` puts the menu as it is set into the manifest's `runtime.menu`: the profile, the
  module labels in and out of the menu, and the number of reports. The page reads them as `{runtime.menu.<key>}`
  and `visibleIf`.
