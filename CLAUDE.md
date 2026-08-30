# Pipelinq — Agent Instructions

Pipelinq is a Nextcloud app built on the `@conduction/nextcloud-vue` component
library (source lives at `../nextcloud-vue/`, not `node_modules/`). It renders
its shell from `src/manifest.json` via the library's manifest renderer
(`CnAppRoot` / `CnPageRenderer`).

## Styling conventions

- **Table-row side accents use an inset box-shadow, NEVER `border-left`.** When
  highlighting a table row — e.g. a `row-class` applied to `CnDataTable` /
  `CnIndexPage` rows such as `.lead-overdue` in [src/views/leads/LeadList.vue](src/views/leads/LeadList.vue) —
  draw the colored left accent with an inset box-shadow, not a border.
  `border-left: 3px solid …` adds layout width and shifts the row's cell content
  sideways; box-shadow paints inside the box without moving anything. Use the
  same pattern as the library's `.cn-table-row--selected`:

  ```css
  box-shadow: inset 3px 0 0 0 var(--color-error);
  ```

  This applies to table rows only. Cards, timeline items, and detail items
  (e.g. `.work-card--overdue`) whose padding accommodates a left border may keep
  `border-left`.

- **Theming**: use Nextcloud CSS variables (`var(--color-error)`,
  `var(--color-primary-element)`, …) — never hard-coded colors.
