---
kind: code
---

## Why

`@conduction/nextcloud-vue` ships `CnStatusBadge` specifically to end this pattern — its own
docblock says: *"Replaces the various `.status-badge` / `.priority-badge` CSS patterns
duplicated across Pipelinq and Procest"*
(`nextcloud-vue/src/components/CnStatusBadge/CnStatusBadge.vue:14-17`). Pipelinq already
consumes it correctly in some views (`src/views/export/ExportRunDetail.vue:11`,
`src/views/blasts/BlastMonitor.vue`), but at least six other views still hand-roll their own
`.status-pill` / `.priority-badge` CSS with hardcoded hex colors instead — the exact
duplication the component exists to retire:

- `src/views/projects/ProjectDetail.vue:845-932` — `.kpi-card__value--warn`, `.status-pill--*`
  (5 variants), `.billable-dot--*`, `.ledger-card__pill--*` (3 variants) — 19 hardcoded hex
  values (`#c62828`, `#e3f2fd`/`#0d47a1`, `#fff8e1`/`#6d4c00`, `#ede7f6`/`#4527a0`,
  `#e8f5e9`/`#1b5e20`, `#fbe9e7`/`#b71c1c`, `#43a047`, `#b0bec5`).
- `src/components/ProjectWbsTree.vue:33,57,373-386` — `.status-pill--open/in_progress/on_hold`
  reimplement the exact same palette as `ProjectDetail.vue`, verbatim duplicated a second time.
- `src/views/pipeline/PipelineBoard.vue:1231-1281` and `src/views/pipeline/PipelineCard.vue:551-620`
  — a third, differently-toned palette (`#dbeafe`/`#1d4ed8`, `#ffedd5`/`#c2410c`,
  `#fef3c7`/`#92400e`, `#fee2e2`/`#991b1b`) for the same badge concept, duplicated between the
  board and the card.
- `src/views/MyWork.vue:622-629` and `src/views/queues/QueueDetail.vue:357-362` — a
  `.priority-badge` class duplicating the `PipelineBoard`/`PipelineCard` palette a fourth and
  fifth time.
- `src/components/products/ProductVariantPanel.vue:287-294` — a sixth ad hoc badge palette
  (`#dcfce7`/`#166534`, `#f3f4f6`/`#6b7280`).

Four distinct hardcoded palettes for the same "status/priority pill" concept, spread across six
files, none of them using NC CSS variables. This violates pipelinq's own `CLAUDE.md` ("Theming:
use Nextcloud CSS variables … never hard-coded colors") and the app's most basic accessibility/
theming contract: these pills render with the same fixed light-mode colors regardless of the
user's NC theme (dark mode, high-contrast, nldesign overrides all pass through NC CSS variables,
which none of these hex literals participate in).

## What Changes

- Replace the six hand-rolled badge implementations with `CnStatusBadge` (`variant` +
  `colorMap`), matching the pattern already used in `ExportRunDetail.vue` / `BlastMonitor.vue`.
- Delete the now-dead `.status-pill--*`, `.priority-badge`, `.kpi-card__value--warn`,
  `.ledger-card__pill--*` CSS rules and their hardcoded hex values from all six files.
- Keep `.billable-dot` / `.color-swatch` (small solid-color indicator dots, not badges) as-is —
  out of scope; `CnStatusBadge` renders a labeled pill, not a bare dot.
- No BREAKING change: this is a visual/markup swap behind the same badge concept: labels and
  triggering conditions are unchanged, only the rendering component and color source change.

## Capabilities

### New Capabilities
- `status-indicator-consistency`: cross-cutting requirement that status/priority pill badges in
  pipelinq use the shared `CnStatusBadge` component rather than app-local CSS.

## Impact

- `src/views/projects/ProjectDetail.vue`, `src/components/ProjectWbsTree.vue`,
  `src/views/pipeline/PipelineBoard.vue`, `src/views/pipeline/PipelineCard.vue`,
  `src/views/MyWork.vue`, `src/views/queues/QueueDetail.vue`,
  `src/components/products/ProductVariantPanel.vue` — template + `<script>` (import
  `CnStatusBadge`) + `<style>` (delete dead hex-color rules).
- No backend, no manifest, no schema changes.
