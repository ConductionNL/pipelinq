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

- [ ] Create `src/views/blasts/BlastForm.vue` multi-step: name → segment → template → channel → schedule → A/B split
- [ ] Validation: required fields; pre-send compliance check; missing-consent modal (skip / request / cancel); template validate for email
- [ ] On submit POST `/api/blasts`; on success navigate to BlastMonitor; inline errors on failure

## BlastMonitor.vue (Task 3.3 of giant)

- [ ] Create `src/views/blasts/BlastMonitor.vue` with progress bar + ETA + totals grid + event timeline (last 50, reverse chronological)
- [ ] Poll `GET /api/blasts/:id` every 2s; update totals/progress/timeline; stop on "sent"/"failed"
- [ ] Cancel button when "sending" → POST `/api/blasts/:id/cancel`
- [ ] nl + en i18n strings; CSS variables (no hardcoded colors)
