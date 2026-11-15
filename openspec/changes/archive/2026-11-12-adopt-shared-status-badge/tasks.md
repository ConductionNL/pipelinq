## 1. Inventory + colorMap design

- [ ] 1.1 Confirm `CnStatusBadge` is already imported from `@conduction/nextcloud-vue` in
      pipelinq (`src/views/export/ExportRunDetail.vue:91`) — no barrel/import changes needed.
- [ ] 1.2 For each of the six files below, map the existing status/priority values to
      `CnStatusBadge`'s closed `variant` set (`default | primary | success | warning | error |
      info`) via a `colorMap` prop, matching the semantic intent of the current hex choice
      (e.g. the greens → `success`, ambers → `warning`, reds → `error`, blues → `info`).

## 2. `src/views/projects/ProjectDetail.vue`

- [ ] 2.1 Replace the `.kpi-card__value--warn` span with `CnStatusBadge`/plain text using
      `var(--color-error)` for the value color (this one is a value, not a pill — keep as text,
      just swap the hex for the CSS var).
- [ ] 2.2 Replace the 5 `.status-pill--*` project-status pills with
      `<CnStatusBadge :label="..." :color-map="{...}" />`.
- [ ] 2.3 Replace the 3 `.ledger-card__pill--*` (synced/pending/failed) pills with
      `CnStatusBadge`.
- [ ] 2.4 Delete the dead `.status-pill*`, `.ledger-card__pill--*`, `.kpi-card__value--warn` CSS
      rules (lines 845-932 today).

## 3. `src/components/ProjectWbsTree.vue`

- [ ] 3.1 Replace the two `.status-pill` usages (phase status, task status; lines 33 and 57)
      with `CnStatusBadge` using the same `colorMap` as task 2.2 (identical palette — dedupes the
      second copy).
- [ ] 3.2 Delete the dead `.status-pill--*` CSS rules (lines 373-386).

## 4. `src/views/pipeline/PipelineBoard.vue` + `src/views/pipeline/PipelineCard.vue`

- [ ] 4.1 Replace the shared badge markup in both files with `CnStatusBadge` + one common
      `colorMap` (define once, e.g. in a small shared constants module, to avoid a third copy of
      the map itself).
- [ ] 4.2 Delete the dead badge CSS rules in both files (`PipelineBoard.vue:1231-1281`,
      `PipelineCard.vue:551-620`).

## 5. `src/views/MyWork.vue` + `src/views/queues/QueueDetail.vue`

- [ ] 5.1 Replace the `.priority-badge` usages (template refs at `MyWork.vue:75`,
      `QueueDetail.vue:56`) with `CnStatusBadge`, reusing the `colorMap` from task 4.1 (same
      palette).
- [ ] 5.2 Delete the dead `.priority-badge`/related CSS rules (`MyWork.vue:622-629`,
      `QueueDetail.vue:357-362`).

## 6. `src/components/products/ProductVariantPanel.vue`

- [ ] 6.1 Replace the ad hoc badge with `CnStatusBadge` + its own `colorMap` (green=success,
      gray=default).
- [ ] 6.2 Delete the dead CSS rules (lines 287-294).

## 7. Verification

- [ ] 7.1 Grep the repo for any remaining `.status-pill`/`.priority-badge` class definitions
      outside `CnStatusBadge` itself — confirm zero remain.
- [ ] 7.2 Manually confirm (screenshot or Playwright) that badges render correctly in both light
      and dark NC themes (the whole point of the migration — hex literals do not respond to
      theme, `CnStatusBadge`'s CSS-variable-based variants do).
- [ ] 7.3 Run the app's lint/test suite; fix any pre-existing warnings touched in these files
      (CLAUDE.md rule).
