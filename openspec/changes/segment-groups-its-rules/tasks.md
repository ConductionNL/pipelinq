# Tasks: segment-groups-its-rules

## 1. nc-vue: the group editor (ConductionNL/nextcloud-vue)

- [x] 1.1 `CnConditionGroupEditor`: `v-model` group tree, `fields`, `operators` by field type, `maxDepth` (default 1), `errors` by node path; rows reuse `CnConditionListEditor`'s field, operator and value row
  - Files: src/components/CnConditionGroupEditor/ (new), src/components/CnConditionListEditor/ (extract the row)
  - Test: vitest for add and remove condition and group, the AND/OR radio, the caption, Add group only above `maxDepth`, a deeper tree rendering, a NOT node as a locked row
- [x] 1.2 The board look: legend and radios as PqSegmentBouwer draws them, connector column, indented inner card, written Remove group, icon pill for Remove condition (PL5-C items 9 to 11 ride on this)
- [x] 1.3 Strings in nc-vue's l10n: How the conditions combine, Match all conditions, Match any condition, Where, and, or, all of these, one of these, Add condition, Add group, Remove group, Remove condition, These conditions are kept as written
- [x] 1.4 Export it, document it in the nc-vue docs beside `CnConditionListEditor`
- [ ] 1.5 Follow-up, not this change: a `conditionGroup` field in nc-vue's form dialog plus posting to a named endpoint, so `SegmentFormDialog` can become manifest config

## 2. pipelinq: the editor

- [x] 2.1 `SegmentBuilder` mounts `CnConditionGroupEditor` with `fieldOptionsFor()` and an operator map built from today's `OPERATORS_BY_TYPE` and `DATE_OPERATORS`; delete `SegmentRuleNode.vue`
  - Files: src/components/SegmentBuilder.vue, src/components/SegmentRuleNode.vue (removed), src/services/segmentOperators.js (new, moved out of SegmentRuleNode)
  - Test: tests/vitest/SegmentBuilder.spec.js (tree in, tree out, no preview while a group is empty)
- [x] 2.2 `cloneTree()` normalises per design D3
  - Test: tests/vitest/SegmentBuilder.spec.js (bare leaf, leaf list, missing type, empty)
- [x] 2.3 e2e for the marketing-ui scenarios: build a group, mix AND/OR, remove, preview count, save and reopen, flat segment, bare condition, deeper tree
  - Files: tests/e2e/spec-coverage/marketing.spec.ts

## 3. pipelinq: the engine

- [x] 3.1 `SegmentService::normaliseRules()` called by `validateRules()`, `evaluateRules()` and the read of a stored segment; the depth cap of 5
  - Files: lib/Service/SegmentService.php
  - Test: tests/Unit/Service/SegmentServiceTest.php (testBareLeafRulesReadAsOneAndGroup, testLeafListRulesReadAsOneAndGroup, testTreeDeeperThanFiveGroupsIsRefused)
  - done: `lib/Service/Marketing/SegmentRuleTree.php` (`normalise()`, `depth()`, `MAX_DEPTH` 5), used by `validateRules()`, `evaluateRules()`, `extractRules()` (the read of a stored segment) and before a save, so a save writes the normalised tree. A lone condition is still validated where it stands, so its error paths keep pointing at `$`. Tests: `SegmentServiceTest` (the three named), `SegmentRuleTreeTest`; `SegmentServiceMembersGoldenTest::testExtractRules` now expects the group form.
- [x] 3.2 `SegmentQueryCompiler`: the operator table of D4, signal and dotted leaves left out, the three paths
  - Files: lib/Service/Marketing/SegmentQueryCompiler.php (new), lib/Service/SegmentService.php (`countMatchingEntities()`, `getMembersForBlast()`, `previewSegmentMembers()`)
  - Test: tests/Unit/Service/SegmentQueryCompilerTest.php (testRootAndPushesCompilableChildren, testCompiledAndPhpPathsAgreeOnSeedSegments, testNotEqualsCompilesWithIsNull)
  - Depends on: ConductionNL/openregister `list-query-condition-groups`; until it lands, the feature check keeps the PHP path and the tests run against a stubbed ObjectService
  - done 2026-11-14: `lib/Service/Marketing/SegmentQueryCompiler.php`, used by `SegmentService::loadEntitiesForType()` for all three paths (count, preview, members). The compiled `_where` is a SUPERSET of what the rules match and the whole tree is still evaluated in PHP over the rows it returns, so narrowing cannot change who is in a segment. That differs from D4's table in three places, each because pipelinq's engine reads the value differently from OpenRegister: `equals` on text becomes `like` (the engine ignores case, `eq` does not), `gte`/`lte`/`between` also keep rows without a value (the engine counts a missing number as in range), and `before`/`after`, `in`/`notIn`/`containsAny` and array or boolean properties are not sent. An OR is sent only when every child compiles; a NOT never is. A narrowed read OpenRegister refuses falls back to every row. D4's one-query count (`_limit=0`, read the total) is not used: the rows are still read and checked. Tests: `SegmentQueryCompilerTest` (the three named, and the operators left out), `SegmentServiceNarrowingTest::testCompiledAndPhpPathsAgreeOnSeedSegments` over the Zuiddrecht segments against a fake that applies `_where` as OpenRegister's condition builder does.
- [x] 3.3 Feature check: read OpenRegister's `listQuery.where` capability once and cache it
  - done 2026-11-14: `lib/Service/Marketing/ListQueryWhereCapability.php` reads `listQuery.where` from OpenRegister's `ApiCapabilitiesServiceInterface::publicCapabilities()` and keeps the answer an hour; anything but an explicit `true` keeps the PHP path. OpenRegister does not publish the key yet (its task 1.6), so on every instance today nothing changes; the key name must match what 1.6 ships.

## 4. pipelinq: access

- [x] 4.1 `SegmentController::create()` calls `isPrivileged()` before the service call, like `update()`
  - Files: lib/Controller/SegmentController.php
  - Test: tests/Unit/Controller/SegmentControllerTest.php (testCreateRefusesNonPrivilegedUser, testUpdateAllowsAnyPrivilegedUser)
  - done: already on development: `create()` goes through `crmGate()->privilegedUserId()`; `SegmentControllerTest::testCreateRefusesANonPrivilegedCaller` covers it.
- [ ] 4.2 Newman: a non-privileged user gets 403 on `POST /api/segments` and nothing is stored

## 5. Board and live

- [ ] 5.1 The PqSegmentBouwer capture builds the board's tree: Place equals Zuiddrecht, Add group, Match any condition, Industry horeca and detailhandel, then Last contact after a date. Then re-triage PL5-C items 2 to 7 and 12 to 17 against it
- [ ] 5.2 Live: build the board's segment on PqSegmentBouwer and read its size (shares the open live task 1.3 of `segment-last-contact-signal`)

## 6. Specs

- [ ] 6.1 After the build, sync the deltas into `openspec/specs/marketing-ui/spec.md` and `openspec/specs/marketing-segmentation/spec.md`
