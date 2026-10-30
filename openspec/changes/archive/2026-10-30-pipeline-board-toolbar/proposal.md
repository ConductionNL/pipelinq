---
kind: code
---

# Proposal: pipeline-board-toolbar

## Why

Board PqPipeline draws the pipeline board's toolbar as every board index draws
it: the chips with Filter and the view switch on row 1, then the pipeline, the
search, Pipeline settings and Columns, then the hint and the active filters.
In the board look the page showed Nextcloud's selects, a switch and icon
buttons instead.

## What changes

- In the board look the board's toolbar is `CnActionsBar` (layout `board`):
  the chips, a Filter menu (type, highest score first) counting the active
  filters, and the view switch (list, cards to the leads list, board, map
  unavailable).
- Row 2: "Pipeline:" select, the search field, Pipeline settings as a link,
  "Columns: stage".
- Row 3: the hint and the active filters (pipeline, type, chip, search) with
  Clear all.
- The Nextcloud look keeps its toolbar.
