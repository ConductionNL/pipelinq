# Design — marketing-segments-ui-repair

## Context

Two independent defects block phase 1 of the marketing programme
(`docs/Technical/marketing-architecture.md`):

1. `SegmentBuilder.vue` / `SegmentRuleNode.vue` exist and are unit-adjacent
   correct, but nothing mounts them.
2. `SegmentService::resolveSchemaProperties()` crashes on every call against
   a current OpenRegister (pipelinq#773), so even a mounted builder could
   not validate a rule tree.

Fixing (2) without (1) leaves the component still unreachable. Fixing (1)
without (2) mounts a component whose every save attempt 400s. Both are in
this change.

## Decisions

### D1 — The Segments/Templates pages are index + custom-form, not full custom

The Blasts page precedent (`type: "index"` list + a custom wizard for
create) is followed rather than inventing a new pattern. Segments and
Templates are simpler than the Blast wizard (no multi-step, no compliance
gate on save itself — the compliance gate lives inside `POST /api/templates`
already), so **one** custom form component serves both New and Edit, with
edit mode driven by a route `:id` param — the same convention
`PosTransactionForm.vue` already uses in this codebase.

### D2 — SegmentBuilder needs two new endpoints it does not have

`SegmentBuilder.vue` (written but never mounted, so never proven against a
real backend) calls `POST /apps/pipelinq/api/segments/validate` and
`POST /apps/pipelinq/api/segments/size` — neither exists. `SegmentService`
already has `previewRulePayload(rules, entityType)`, which validates then
estimates in one call and is exactly what an unsaved rule tree needs (there
is no Segment id yet for `POST /api/segments/{id}/size` to recompute
against). One endpoint, `POST /api/segments/preview`, is added, and
`SegmentBuilder.vue`'s two debounced calls (`runEstimate`, `runValidate`)
are both rewired onto it through a shared `runPreview()` helper.

Editing an existing Segment also has no backend support today — only
`create()` exists. `PATCH /api/segments/{id}` is added, backed by
`SegmentService::updateSegment()`, reusing the same `validateRules()` →
`saveSegmentObject()` sequence `createSegment()` already uses.

### D3 — The operator vocabulary in SegmentRuleNode.vue was never correct

`SegmentRuleNode.vue`'s `OPERATORS_BY_TYPE` used `eq` / `ne` / `starts_with`
/ `is_empty` / `on`, which none of `SegmentService::OPERATOR_TYPE_MATRIX`,
the seed data (`lib/Settings/register.d/95-marketing-segmentation-blast.json`),
or the unit tests recognise. Since the component was never mounted, this was
never exercised against the real validator. Corrected to the canonical
vocabulary (`equals`, `notEquals`, `greaterThan`, …) the backend already
uses everywhere else. Operators requiring an array value (`in`, `notIn`,
`containsAny`, `between`) are deliberately not offered — the leaf UI has one
scalar value input, and adding array-value editing is a larger UI change
than this repair warrants.

### D4 — Field options are a static curated list, not a schema-introspection endpoint

SegmentBuilder needs `fieldOptions` (`{value, label, type, format}`) for the
selected audience. Building a live schema-properties API is materially
larger scope than this change (a new introspection endpoint, a new gate
surface, a new frontend fetch/cache path). The two static lists in
`SegmentForm.vue` are grounded in the real `contact` and `client` schema
properties (`lib/Settings/pipelinq_register.json`), not invented fields —
see DEFERRED_QUESTIONS.

## Open Questions

None blocking. See `proposal.md`'s deferred-decisions note for the calls
made under uncertainty (field-option source, per-leaf error attribution
granularity, and the scope boundary on marketing-segmentation's remaining
`@e2e exclude` scenarios).
