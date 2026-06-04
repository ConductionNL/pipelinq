# Tasks: 07 Segment and Blast Views

## SegmentBuilder.vue (Task 3.1 of giant)

- [ ] Create `src/components/SegmentBuilder.vue` with props `modelValue`, `entityType`
- [ ] Render recursive rule tree: AND/OR selector, add-condition, add-group, remove per node
- [ ] Predicate form: field dropdown from entity schema, operator dropdown filtered by type, value input by type
- [ ] Real-time validation on blur via POST validate; field-level errors
- [ ] Debounced size estimation call; display estimate or spinner; emit `update:modelValue`

## BlastForm.vue (Task 3.2 of giant)

- [ ] Create `src/views/blasts/BlastForm.vue` multi-step: name → segment → template → channel → schedule → A/B split
- [ ] Validation: required fields; pre-send compliance check; missing-consent modal (skip / request / cancel); template validate for email
- [ ] On submit POST `/api/blasts`; on success navigate to BlastMonitor; inline errors on failure

## BlastMonitor.vue (Task 3.3 of giant)

- [ ] Create `src/views/blasts/BlastMonitor.vue` with progress bar + ETA + totals grid + event timeline (last 50, reverse chronological)
- [ ] Poll `GET /api/blasts/:id` every 2s; update totals/progress/timeline; stop on "sent"/"failed"
- [ ] Cancel button when "sending" → POST `/api/blasts/:id/cancel`
- [ ] nl + en i18n strings; CSS variables (no hardcoded colors)
