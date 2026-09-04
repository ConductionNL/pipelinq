## 1. Backend fix (pipelinq#773)

- [x] 1.1 Fix `SegmentService::resolveSchemaProperties()` to call `SchemaMapper::find()` without the removed `published` argument, and update the fake `SchemaMapper` in `tests/Unit/Service/SegmentServiceTest.php` to the real signature — verify with `vendor/bin/phpunit tests/Unit/Service/SegmentServiceTest.php`
- [x] 1.2 Add `SegmentService::updateSegment()` + `SegmentController::update()`/`preview()` and their routes (`PATCH /api/segments/{id}`, `POST /api/segments/preview`) — verify with `vendor/bin/phpunit tests/Unit/Controller/SegmentControllerTest.php` and `composer check:strict`

## 2. Frontend wiring

- [x] 2.1 Correct the operator vocabulary in `SegmentRuleNode.vue` to match `SegmentService::OPERATOR_TYPE_MATRIX`, and rewire `SegmentBuilder.vue`'s validate/estimate calls onto `POST /api/segments/preview` — verify with `npm run lint`
- [x] 2.2 Add `SegmentForm.vue` and `TemplateForm.vue`, register both in `src/registry.js` — verify with `npm run lint` and `npm run format`
- [x] 2.3 Add Segments/SegmentNew/SegmentEdit/Templates/TemplateNew/TemplateEdit pages to `src/manifest.d/75-marketing-blasts.json` and reorder the Marketing menu (Segments, Templates, Blasts, Blast performance) — verify with `npm run check:manifest`

## 3. Tests and traceability

- [x] 3.1 Add unit tests for `SegmentService::updateSegment()` and `SegmentController::update()`/`preview()` — verify with `vendor/bin/phpunit`
- [x] 3.2 Add Playwright coverage for "Segment Builder UI Composes Rule Trees" and "Segment create validates rule tree" to `tests/e2e/spec-coverage/marketing.spec.ts`, and remove the `@e2e exclude` markers those two scenarios carried in `openspec/specs/marketing-ui/spec.md` and `openspec/specs/marketing-api/spec.md` — verify with `npx eslint tests/e2e/spec-coverage/marketing.spec.ts` (Playwright itself is not run locally; see the PR body)

## 4. Docs and spec maintenance

- [x] 4.1 Update `docs/user/marketing-blasts.md` and `.nl.md` where the Marketing navigation changed
- [x] 4.2 Sync the `marketing-ui` and `marketing-api` delta specs into `openspec/specs/`, flipping their frontmatter `status` to `in-progress` while this change is open — verify with `node scripts/check-spec-links.js`
