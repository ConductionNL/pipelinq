# marketing-segmentation Specification Delta

## Purpose

Documents the pipelinq#773 fix inside the rule-tree validation requirement:
`SegmentService::resolveSchemaProperties()`'s call to OpenRegister's
`SchemaMapper::find()` is corrected to the collaborator's current signature.
The two scenarios below keep an `@e2e exclude`, but with an updated reason —
the crash they described is fixed, and the same `POST /api/segments` path is
now covered by the dedicated e2e tests added in `marketing-ui` and
`marketing-api`; a third, differently-named test asserting the identical
HTTP behaviour would duplicate rather than add coverage. Direct, separately
-named e2e coverage of these two scenarios is out of scope for this change
(see DEFERRED_QUESTIONS in `proposal.md`).

## MODIFIED Requirements

### Requirement: Segment Builder Composes Rule Trees

The segment service SHALL validate rule trees using AND/OR logic with leaf
predicates (field, operator, value). Each predicate SHALL be validated
against the entity schema before save.

`resolveSchemaProperties()` calls `$schemaMapper->find(id: $schemaSlug,
_rbac: false, _multitenancy: false)` — no `published` argument, matching
`OCA\OpenRegister\Db\SchemaMapper::find(string|int $id, ?array $_extend =
[], bool $_rbac = true, bool $_multitenancy = true)`. The unit-test fake
`SchemaMapper` in `tests/Unit/Service/SegmentServiceTest.php` now declares
the same signature, so a future signature drift there fails the test
instead of passing silently.

#### Scenario: Rule tree validated on save

@e2e exclude the crash this exclusion originally described (`SchemaMapper::find()`'s removed `$published` parameter, pipelinq#773) is fixed by this change — `resolveSchemaProperties()` no longer passes it. This exact scenario wording ("on save") does not get its own dedicated e2e test in this change: the same `POST /api/segments` code path is exercised end to end by `tests/e2e/spec-coverage/marketing.spec.ts`'s "Segment create validates rule tree" (marketing-api) and the SegmentBuilder UI flow (marketing-ui "Visual rule tree with live validation"), which together prove the validate-then-save sequence; a third test asserting the identical HTTP behaviour under a different name would duplicate coverage rather than add it. Revisit if a save-specific regression (as opposed to a validate-specific one) is ever suspected.

- **GIVEN** a rule `industry = "gemeente" AND (employees > 50 OR annual_revenue > 5000000) AND last_contact_moment < 90 days`
- **WHEN** the segment is saved
- **THEN** the system SHALL serialize the rule tree as JSON and call `SegmentService.validateRules()` to verify each leaf predicate (field exists, operator valid for type, value coercible)
- **AND** on validation success SHALL save a Segment object with the rule tree
- **AND** on validation failure SHALL return field-level errors and block save

#### Scenario: Estimated size computed

- **GIVEN** a validated rule tree
- **WHEN** the segment detail is requested
- **THEN** the system SHALL return the count from `SegmentService.estimateSize()`
- **AND** the estimate SHALL be cached (default 1 hour TTL) to avoid repeated full-table scans

#### Scenario: Operators validated per field type

@e2e exclude the crash this exclusion originally described (pipelinq#773) is fixed by this change, so the operator/type matrix is reached over HTTP again. This exact scenario is not given its own e2e test here: `tests/e2e/spec-coverage/marketing.spec.ts`'s "Segment create validates rule tree" (marketing-api) submits an operator invalid for its field's type as part of proving the validator runs, which is this scenario's assertion under a different scenario name. Give this its own named e2e test if the operator/type matrix needs to be pinned independently of that assertion.

- **GIVEN** a contact schema with `industry` (string), `employees` (integer), `last_contact_moment` (date)
- **WHEN** a predicate `industry > 50` is validated (string field with numeric operator)
- **THEN** `validateRules()` SHALL reject the predicate with an operator-not-valid-for-type error
