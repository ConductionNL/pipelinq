# Proposal: modules-page-as-board

## Why

Board PqModules draws "Modules en meer" as six module cards (icon, title, an in-the-menu pill, a description, the pages as
links, Open, and for a module that is off the way to the admin settings), the count beside the heading and a note under
the cards, then "Ook in Pipelinq" and "Meer" as list cards. Live drew 31 link cards under seven captions (pl2: 15 items).

## What changes

- `98-modules.json`: the six modules as `modules` (nextcloud-vue 2.108.0), in the board order, each with its pages; the
  pages that belong to no module as two list groups (`groupStyle: list`): Also in Pipelinq and More (Store, Flows,
  Features and roadmap, Reports, Documentation).
- `menuRuntime`: `runtime.menu.enabled.<module>` says whether a module is in the menu (every module in the full profile),
  which each module's `enabledIf` reads.
- Translations for the new strings.
