## 1. ProspectWidget expand/collapse toggle

- [ ] 1.1 In `src/components/ProspectWidget.vue`, add `role="button"`, `tabindex="0"`,
      `:aria-expanded="expanded"`, an `aria-label` (e.g. `t('pipelinq', 'Expand prospect
      discovery')` / a collapse variant, or one label plus `aria-expanded` alone per WCAG guidance
      — use the same label with `aria-expanded` toggling), and `@keydown.enter="expanded =
      !expanded"` + `@keydown.space.prevent="expanded = !expanded"` to the
      `.prospect-widget__header` div.
- [ ] 1.2 Verify the header's child elements (title, count, any icon/chevron) are not themselves
      independently focusable in a way that would create a nested/duplicate tab stop.

## 2. ComplaintsOverviewWidget navigation

- [ ] 2.1 In `src/views/widgets/ComplaintsOverviewWidget.vue`, add `role="button"`,
      `tabindex="0"`, an `aria-label` (e.g. `t('pipelinq', 'Open complaints overview')`), and
      `@keydown.enter="$router.push({ name: 'Complaints' })"` +
      `@keydown.space.prevent="$router.push({ name: 'Complaints' })"` to the root
      `.complaints-widget` div (extract the navigation into a named method if that reads cleaner
      than repeating the router call).

## 3. ProjectWbsTree phase-row toggle

- [ ] 3.1 In `src/components/ProjectWbsTree.vue`, add `role="button"`, `tabindex="0"`,
      `:aria-expanded="isOpen(phase.id)"`, an `aria-label` bound to the phase name (e.g.
      `t('pipelinq', 'Toggle phase {name}', { name: phase.name })` — coordinate with the
      i18n-key-hygiene change for the English source string), and
      `@keydown.enter="toggle(phase.id)"` + `@keydown.space.prevent="toggle(phase.id)"` to each
      `.wbs-phase__row` div.
- [ ] 3.2 Confirm the status pill and any inline action inside the row do not end up nested inside
      the new keyboard-activatable region in a way that double-handles Enter/Space.

## 4. FindClientWidget result-row navigation

- [ ] 4.1 In `src/views/widgets/FindClientWidget.vue`, add `role="button"`, `tabindex="0"`, an
      `aria-label` bound to the client name (e.g. `t('pipelinq', 'Open client {name}', { name:
      toText(client.name) })`), and `@keydown.enter="viewClient(client)"` +
      `@keydown.space.prevent="viewClient(client)"` to each `.client-info` div.

## 5. Verify

- [ ] 5.1 Tab through the dashboard (Prospect widget, Complaints widget, Find Client widget) and
      the Project detail WBS tree using keyboard only; confirm each control receives visible focus
      and Enter/Space activates it identically to a mouse click.
- [ ] 5.2 Run `npm run lint` (the `@nextcloud/eslint-config` `vuejs-accessibility` rules should now
      pass clean on these four files) and fix any newly-surfaced pre-existing lint issues in the
      touched files per CLAUDE.md.
- [ ] 5.3 Run the existing Vitest component suites for these four components (if any) and confirm
      they still pass; add a minimal interaction test asserting keydown-Enter triggers the same
      state change as click, if the house pattern for this repo's component tests supports it.
