---
kind: code
---

## Why

Four interactive controls in pipelinq are plain `<div>` elements with a `@click` handler and no
keyboard path — no `role`, no `tabindex`, no `@keydown.enter`/`@keydown.space`. A keyboard-only or
switch-device user cannot Tab to them or activate them; a screen-reader user gets no indication
they are interactive (fails WCAG 2.1 AA 2.1.1 Keyboard and 4.1.2 Name, Role, Value):

- `src/components/ProspectWidget.vue:3` — `<div class="prospect-widget__header" @click="expanded =
  !expanded">` is the only way to expand/collapse the Prospect Discovery dashboard widget.
- `src/views/widgets/ComplaintsOverviewWidget.vue:2` — the entire widget body,
  `<div class="complaints-widget" @click="$router.push({ name: 'Complaints' })">`, is the only way
  to navigate to the Complaints list from the dashboard.
- `src/components/ProjectWbsTree.vue:28` — `<div class="wbs-phase__row" @click="toggle(phase.id)">`
  is the only way to expand/collapse a phase row in the project WBS tree.
- `src/views/widgets/FindClientWidget.vue:44` — `<div class="client-info"
  @click="viewClient(client)">` is the only way to open a client from the Find Client dashboard
  widget's search results.

Every other bespoke clickable-`<div>` found in the same sweep (`PipelineBoard.vue`,
`QueueDetail.vue`, `PipelineCard.vue`, the `create-overlay` backdrop-dismiss `@click.self` pattern
in `LeadProducts.vue`/`ContactRelationships.vue`/`LeadContactRoles.vue`) is either a `@click.stop`
guard around real focusable children, a modal-backdrop dismiss (not the primary interaction), or
already has a keyboard-accessible alternative (e.g. `PipelineCard`'s drag-and-drop also exposes a
"Move to stage" button). Only the four above are themselves the sole, primary interactive control
with no keyboard equivalent — this change is scoped to exactly those four.

## What Changes

- Add `role="button"`, `tabindex="0"`, an `@keydown.enter`/`@keydown.space` handler calling the
  same method as `@click` (with `.prevent` on space to stop page scroll), and a descriptive
  `aria-label` (or `aria-expanded` for the two toggle rows) to each of the four elements above.
- No visual or layout change — this only adds accessibility attributes/handlers to elements that
  already exist; CSS `cursor: pointer` stays as-is.
- Not BREAKING: purely additive keyboard/ARIA support, no prop or event contract changes.

## Impact

- `src/components/ProspectWidget.vue`
- `src/views/widgets/ComplaintsOverviewWidget.vue`
- `src/components/ProjectWbsTree.vue`
- `src/views/widgets/FindClientWidget.vue`
