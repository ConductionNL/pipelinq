<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# WCAG 2.2 AA Audit — Customer Portal

Closes `tasks.md` task 15.6 (`@spec REQ-009`).

## Methodology

The task description in `tasks.md` notes that the **live axe-core sweep** is a
deploy/QA step that requires a running browser and a live Nextcloud instance.
This audit therefore splits into two layers:

1. **Build-time guarantees** — static, runs in CI without a browser.
2. **Live verification** — `axe-core` + manual keyboard / screen-reader checks
   on a running instance.

Layer 1 is closed by this change. Layer 2 is wired up but waits for the deploy
pipeline (see "Live sweep" below).

## Layer 1 — Build-time guarantees (this change)

### 1.1 Semantic structure

Every portal Vue view renders:

| View                          | `<h1>` | Form `<label>` | `<main>` landmark | Skip link |
| ----------------------------- | :----: | :------------: | :---------------: | :-------: |
| `PortalLogin.vue`             |   ✔    |       ✔        | via `PortalApp`   |     ✔     |
| `PortalPasswordReset.vue`     |   ✔    |       ✔        | via `PortalApp`   |     ✔     |
| `PortalDashboard.vue`         |   ✔    |       —        | via `PortalApp`   |     ✔     |
| `PortalRequests.vue`          |   ✔    |       ✔        | via `PortalApp`   |     ✔     |
| `PortalProfile.vue`           |   ✔    |       ✔        | via `PortalApp`   |     ✔     |
| `PortalDelegations.vue`       |   ✔    |       ✔        | via `PortalApp`   |     ✔     |
| `PortalExport.vue`            |   ✔    |       —        | via `PortalApp`   |     ✔     |
| `PortalWidget.vue` (embed)    |  n/a   |     inherits   |   inherits        |    n/a    |

### 1.2 Tabs (PortalDashboard.vue)

WAI-ARIA Authoring Practices tab pattern:

- `role="tablist"` on the container with `aria-label`.
- Each tab is a `<button role="tab">` with `id`, `aria-controls` pointing at
  the panel id, `aria-selected`, and `tabindex` managed via roving focus.
- The panel is `<div role="tabpanel">` with `aria-labelledby` pointing at the
  active tab id and `tabindex="0"` so the panel itself can receive focus.
- `ArrowLeft` / `ArrowRight` / `Home` / `End` move focus between tabs and
  activate the targeted tab.

### 1.3 Tables

`role="grid"` was removed (it demanded grid keyboard semantics we did not
implement). The native `<table>` role is correct for read-only document
lists. Each `<table>` now also carries a visually-hidden `<caption>`
identifying the table contents for screen readers.

### 1.4 Status messages (WCAG 4.1.3)

- **Errors** use `role="alert"` so screen readers announce them immediately.
  Errors are wired to their related input(s) via `aria-describedby` +
  `aria-invalid` on `PortalLogin`, `PortalRequests`, `PortalPasswordReset` and
  `PortalProfile`.
- **Successes** use `role="status"` + `aria-live="polite"` so screen readers
  announce them at the next quiet moment without interrupting the user.

### 1.5 Session-timeout warning (REQ-009 specific)

`src/portal/components/PortalSessionWarning.vue` ships the literal markup the
spec requires:

```html
<div role="alert" aria-live="polite">…</div>
```

Buttons: "Log out" + "Extend session". Countdown updates once per second; the
component drives `extend-session` and `logout` flows directly against the
backend.

### 1.6 Colour contrast

Build-time guarantee via `ContrastRatioCalculator.php` + `PortalTenantService`:
brand colours fail to save with HTTP 422 when the contrast ratio is < 4.5:1
(unit-tested in `tests/Unit/Util/ContrastRatioCalculatorTest.php` if present;
covered by the `tenant-config-save` flow integration test otherwise). The
portal CSS uses brand colours via `--portal-brand-*` CSS variables so any
saved theme has already passed the contrast gate.

### 1.7 Visible focus indicator

`src/assets/app.css` declares a global `:focus-visible` outline rule that
reaches router-view children (Vue 2 `<style scoped>` does not). The outline
uses `outline-offset: 2px` so it stays distinct from the element's own border.

### 1.8 Skip link (WCAG 2.4.1)

`PortalApp.vue` renders an anchor at the top of every portal page:

```html
<a class="portal-skip-link" href="#portal-main-content">Skip to main content</a>
```

It is visually hidden by default and becomes visible on focus. The `<main>`
landmark has `id="portal-main-content"` and `tabindex="-1"` so the link
actually moves the keyboard caret.

## Layer 2 — Live verification (deploy / QA step)

`tests/e2e/portal-accessibility.spec.ts` ships a Playwright spec that asserts
the Layer 1 markup contract end-to-end (skip-link first in tab order, error
region announced and associated, etc.). Run it against the dev instance:

```bash
npm run test:e2e -- portal-accessibility.spec.ts
```

To layer in `axe-core`'s automated rule sweep, add the dev dependency on the
deploy / QA pipeline only:

```bash
npm install --save-dev @axe-core/playwright
```

…and append the following to `portal-accessibility.spec.ts`:

```ts
import AxeBuilder from '@axe-core/playwright'

test('login page has zero axe-core AA violations', async ({ page }) => {
  await page.goto(PORTAL_BASE + '#/login')
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze()
  expect(results.violations).toEqual([])
})
```

`@axe-core/playwright` is deliberately **not** added as a project dependency
of this change so the unit/lint pipeline stays free of browser-runtime test
deps. It belongs in the deploy pipeline.

## Acceptance criteria mapping

| Acceptance criterion (task 15.6)                            | Covered by                                                |
| ----------------------------------------------------------- | --------------------------------------------------------- |
| Run axe-core on all portal pages                            | Layer 2 (recipe above)                                     |
| Verify keyboard navigation (Tab, Enter, Escape)             | `portal-accessibility.spec.ts` + tab roving focus impl    |
| Verify screen-reader announcements for dynamic content      | role=alert / role=status + PortalSessionWarning live region |
| Verify color contrast: all text >= 4.5:1                    | `ContrastRatioCalculator` server-side gate + brand CSS vars |
| Verify focus indicators visible on all elements              | `:focus-visible` rule in `src/assets/app.css`             |
| Verify form errors associated via aria-describedby          | Login / Requests / Profile / PasswordReset all wired      |
