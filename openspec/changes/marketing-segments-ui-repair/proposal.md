---
kind: mixed
status: in-progress
---

# pipelinq — repair the segment builder and campaign template pages

## Why

Phase 1 of the marketing programme (`docs/Technical/marketing-architecture.md`)
cannot start until two known defects are fixed. First, `SegmentBuilder.vue`
and `SegmentRuleNode.vue` are imported by nothing — no page, route, or
registry entry mounts them, so the Segments and Templates pages the user
guide already describes (`docs/user/marketing-blasts.md`) do not exist and no
marketer can reach the rule-tree editor. Second, `SegmentService::resolveSchemaProperties()`
calls `$schemaMapper->find(id: …, published: null, …)`, and OpenRegister
removed the `$published` parameter from `SchemaMapper::find()` in commit
`ea99a5004`; PHP raises `Error: Unknown named parameter $published`, the
method's own `catch (Throwable)` swallows it, and `validateRules()` rejects
every rule tree with "no schema mapping configured" — tracked as
pipelinq#773. The unit tests pass only because their fake `SchemaMapper`
still declares `$published`, so they cannot catch this class of drift.

## What Changes

- Fix `SegmentService::resolveSchemaProperties()` to call `SchemaMapper::find()`
  with its current signature (`id`, `_rbac`, `_multitenancy` — no `published`).
- Update the fake `SchemaMapper` in `tests/Unit/Service/SegmentServiceTest.php`
  to match OpenRegister's real `find()` signature, so a future signature
  drift fails the test instead of passing silently.
- Add a `Segments` page (`type: index` over the `segment` schema) and
  `SegmentNew` / `SegmentEdit` custom pages that mount `SegmentBuilder.vue`
  with live validation and a debounced member-size estimate, wired through
  the existing `/api/segments` endpoints.
- Add a `Templates` page (`type: index` over the `campaignTemplate` schema)
  with add/edit through a form that calls the template validation endpoint,
  so a missing `{{unsubscribe_link}}` token or postal address block shows as
  a field error before save.
- Reorder the Marketing menu group to Segments, Templates, Blasts, Blast
  performance.
- Add Playwright coverage for the two scenarios this unblocks and remove the
  `@e2e exclude` markers on them once they are reachable.
- Update `docs/user/marketing-blasts.md` and `.nl.md` where navigation
  changed.

## Capabilities

### New Capabilities
(none — Segments and Templates pages are additions to the existing
`marketing-ui` and `marketing-api` capabilities, not a new capability)

### Modified Capabilities
- `marketing-ui`: adds the Segments and Templates pages that mount
  `SegmentBuilder.vue`; removes the `@e2e exclude` on "Segment Builder UI
  Composes Rule Trees" now that the component is reachable.
- `marketing-api`: removes the `@e2e exclude` on "Segment create validates
  rule tree" now that `SegmentService::resolveSchemaProperties()` no longer
  throws on every call.

## Impact

- `lib/Service/SegmentService.php` — one call-site fix.
- `tests/Unit/Service/SegmentServiceTest.php` — fake `SchemaMapper::find()`
  signature corrected.
- `src/manifest.d/75-marketing-blasts.json` — two new pages, menu reorder.
- `src/views/segments/` (new), `src/views/templates/` (new) — page host
  components.
- `src/components/SegmentBuilder.vue`, `src/components/SegmentRuleNode.vue` —
  mounted for the first time; no code changes expected beyond wiring.
- `tests/e2e/spec-coverage/marketing.spec.ts` — two new scenarios.
- `docs/user/marketing-blasts.md`, `.nl.md` — navigation updated.
- `lib/Controller/SegmentController.php` gains two endpoints
  (`PATCH /api/segments/{id}`, `POST /api/segments/preview`) SegmentBuilder
  needs and did not have; `lib/Controller/TemplateController.php` is reused
  as-is (its existing create/update endpoints already run compliance
  validation before persisting).

## Deferred decisions

Made under uncertainty; flag if any of these should be revisited:

- **Field options are a static, schema-grounded list, not a live
  introspection endpoint.** `SegmentForm.vue` offers a curated field list
  per audience (contact/customer), drawn from the real `contact`/`client`
  schema properties in `lib/Settings/pipelinq_register.json`. Building a
  generic schema-properties API is materially more surface than a UI-repair
  change warrants.
- **Per-leaf field-error attribution stays coarse.**
  `SegmentService::validateRules()` returns one error string per request,
  not a per-path map. `SegmentBuilder.vue`'s `errors` (keyed by leaf path)
  is wired to accept a future `fieldErrors` map but stays empty until the
  service returns one; today the single error string is shown at the top of
  the builder. The spec scenario's "field-level error" is satisfied loosely
  (the message names the field; it is not anchored to that leaf's row).
- **Operators requiring an array value are not exposed in the UI.** `in`,
  `notIn`, `containsAny`, `between` are valid in `OPERATOR_TYPE_MATRIX` but
  the leaf editor has one scalar value input; adding array-value editing is
  out of scope here.
- **`marketing-segmentation`'s two related `@e2e exclude` scenarios keep
  their exclusion**, with an updated reason (defect fixed, but no
  dedicated e2e test under that exact scenario name — see that capability's
  delta spec). Only the two scenarios named in the task brief
  (`marketing-ui` "Segment Builder UI Composes Rule Trees",
  `marketing-api` "Segment create validates rule tree") get direct e2e
  coverage in this change.
