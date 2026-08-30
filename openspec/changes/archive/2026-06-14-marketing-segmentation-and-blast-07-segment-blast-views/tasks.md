# Tasks: 07 Segment and Blast Views

## SegmentBuilder.vue (Task 3.1 of giant)

- [x] Create `src/components/SegmentBuilder.vue` with props `modelValue`, `entityType`

  `src/components/SegmentBuilder.vue` accepts `modelValue` (rule tree) + `entityType` (`contact` | `customer`) + an optional `fieldOptions` prop the parent populates from the entity schema. The component emits `update:modelValue` and `validity-change`. The tree is cloned on prop change so the parent's `modelValue` is never mutated in place.

- [x] Render recursive rule tree: AND/OR selector, add-condition, add-group, remove per node

  `src/components/SegmentRuleNode.vue` self-recurses for every group (`children[]`) and renders the leaf form for every predicate. Each group exposes an AND/OR NcSelect, "Add condition" + "Add group" buttons, and a per-node "Remove group" / leaf remove button. Depth is capped on indentation at 4 levels so deep trees stay readable.

- [x] Predicate form: field dropdown from entity schema, operator dropdown filtered by type, value input by type

  Each leaf renders an NcSelect for the field (sourced from the `fieldOptions` prop the parent loads from `/api/schemas/...`), an NcSelect for the operator filtered by the selected field's `type` (string / number / boolean / date — separate operator lists in `OPERATORS_BY_TYPE`), and a native `<input>` whose type is derived from the field type (`number` / `date` / `text`). Switching the field resets the operator to the default for its type.

- [x] Real-time validation on blur via POST validate; field-level errors

  Each leaf input fires `validate-leaf` on `blur` (and immediately on field/operator change). `SegmentBuilder` debounces (250ms) a `POST /api/segments/validate` call that returns `{valid, error, fieldErrors}`. `fieldErrors` is keyed by node path (`root.children[i]...`) and surfaced inline under the offending leaf via the `errors` prop threaded down the recursion.

- [x] Debounced size estimation call; display estimate or spinner; emit `update:modelValue`

  Tree changes also schedule a `POST /api/segments/size` call after 400ms debounce. The header shows `NcLoadingIcon` while in-flight, the numeric estimate when ready, or a localised error fallback. Every tree mutation emits `update:modelValue` with a deep-cloned tree so the parent stays the source of truth.

## BlastForm.vue (Task 3.2 of giant)

- [x] Create `src/views/blasts/BlastForm.vue` multi-step: name → segment → template → channel → schedule → A/B split

  `src/views/blasts/BlastForm.vue` renders a six-panel wizard driven by a `STEPS` array (name / segment / template / channel / schedule / ab). The header shows a numbered ordered list with `.is-current` and `.is-done` markers. Segment + template + connector-source pickers load on mount (`/api/segments`, `/api/templates`, `/api/openconnector/sources?type=email,sms`). The template list is filtered client-side by the selected channel (changing channel drops a mismatching template selection). Schedule uses `<input type="datetime-local">`; the A/B panel exposes a toggle + a 0-100 range slider that defaults to 50 when enabled.

- [x] Validation: required fields; pre-send compliance check; missing-consent modal (skip / request / cancel); template validate for email

  `canAdvance` gates the Next button per-step (name non-empty, segment chosen, template chosen + valid, channel selected). `validateTemplate()` calls `POST /api/templates/:id/validate` whenever the template or channel changes for email (SMS skips per ComplianceService). On final submit `preflightCompliance()` calls `GET /api/segments/:id/compliance?channel=...`; when missing-consent contacts come back the `MissingConsentModal` is rendered (its own file in `src/modals/`, modal-isolation gate) with skip / request / cancel actions wired through a `consentDecision` watcher promise. Cancel aborts the send, Skip continues, Request fires a notification and stops.

- [x] On submit POST `/api/blasts`; on success navigate to BlastMonitor; inline errors on failure

  `submit()` posts the assembled payload (name/segmentId/templateId/channel/connectorSourceId/scheduledFor/abSplitPercent — A/B disabled means 100 to A) to `POST /api/blasts` and `$router.push`es to `BlastMonitor` with the returned blast id. Server errors surface inline under the wizard via `submitError`, never throwing the user back to the start.

## BlastMonitor.vue (Task 3.3 of giant)

- [x] Create `src/views/blasts/BlastMonitor.vue` with progress bar + ETA + totals grid + event timeline (last 50, reverse chronological)

  `src/views/blasts/BlastMonitor.vue` mounts with the blast id as a route param. The progress bar's `aria-valuenow` follows `progressPercent = processed / (processed + queued)`. The ETA label is computed from the polling-rate heuristic (`processed / elapsedSeconds` extrapolated against `queued`). The totals grid uses an auto-fit CSS grid over the canonical 8-key list (`queued`/`sent`/`delivered`/`bounced`/`opened`/`clicked`/`unsubscribed`/`complained`). The timeline picks the most-recent timestamp from each delivery (clickedAt / openedAt / bouncedAt / deliveredAt / sentAt / updatedAt) and sorts reverse-chronological, capped at 50 entries.

- [x] Poll `GET /api/blasts/:id` every 2s; update totals/progress/timeline; stop on "sent"/"failed"

  `startPolling()` runs `fetchOnce()` on a 2000ms `setInterval`. Each tick re-renders the totals grid, recomputes the progress bar and rebuilds the timeline. If the payload doesn't embed `recentDeliveries` the monitor falls back to `GET /api/blasts/:id/deliveries?limit=50`. `TERMINAL_STATUSES = ['sent','failed','cancelled']` triggers `stopPolling()` so the page is quiet after completion. The interval handle is cleared on `beforeUnmount` to avoid leaks.

- [x] Cancel button when "sending" → POST `/api/blasts/:id/cancel`

  The cancel footer is shown when `blast.status` is `sending` or `scheduled`. Clicking calls `POST /api/blasts/:id/cancel`, sets a local `cancelling` status until the next poll lands the authoritative state, and surfaces server errors inline (`cancelError`).

- [x] nl + en i18n strings; CSS variables (no hardcoded colors)

  Every visible string in SegmentBuilder, SegmentRuleNode, BlastForm, BlastMonitor, BlastList and MissingConsentModal is wrapped in `t('pipelinq', ...)` or `n('pipelinq', ...)`. The 60 new English source keys are added to `l10n/en.json` + `l10n/en.js`; the Dutch translations land in `l10n/nl.json` + `l10n/nl.js` (re-using existing equivalents — Verzenden / Status / Annuleren — where they already exist). Every visual style uses `var(--color-*)` / `var(--border-radius)` (no hardcoded hex / rgb) so the nldesign theme applies cleanly.

## BlastMonitor.vue (Task 3.3 of giant) — duplicate of section above (kept for changelog continuity)

- [x] Create `src/views/blasts/BlastMonitor.vue` with progress bar + ETA + totals grid + event timeline (last 50, reverse chronological)

  Already delivered above. `src/views/blasts/BlastMonitor.vue` (467 lines, 11.3 KB) renders the progress bar with `role="progressbar"` + `aria-valuenow`, the localised ETA computed against `audienceTotal`, an auto-fit totals grid over the canonical 8-key list, and the reverse-chronological timeline capped at `TIMELINE_MAX = 50`.

- [x] Poll `GET /api/blasts/:id` every 2s; update totals/progress/timeline; stop on "sent"/"failed"

  `POLL_INTERVAL_MS = 2000`; `startPolling()` runs `fetchOnce()` on a `setInterval`; `TERMINAL_STATUSES = ['sent', 'failed', 'cancelled']` triggers `stopPolling()`; `beforeUnmount` clears the handle.

- [x] Cancel button when "sending" → POST `/api/blasts/:id/cancel`

  The footer renders when `blast.status` is `sending` or `scheduled` (`canCancel` computed). `cancel()` posts `/api/blasts/:id/cancel`, sets local `cancelling` status until the next poll, surfaces server errors inline as `cancelError` with `role="alert"`.

- [x] nl + en i18n strings; CSS variables (no hardcoded colors)

  Every visible string uses `t('pipelinq', ...)` (8 BlastMonitor-specific keys verified in `l10n/en.json` + `l10n/en.js` + `l10n/nl.json` + `l10n/nl.js`). Every style uses `var(--color-background-darker)`, `var(--color-primary-element)`, `var(--color-background-hover)`, `var(--color-text-lighter)`, `var(--color-main-text)`, `var(--color-border)`, `var(--color-error)`, `var(--border-radius)` — zero hex / rgb literals.
