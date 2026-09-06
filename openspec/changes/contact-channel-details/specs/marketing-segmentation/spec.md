## ADDED Requirements

### Requirement: Rule fields reach into array-of-object properties

A rule leaf's `field` MAY be a dotted path `arrayProp.subProp`, naming a sub-property of an array-of-objects schema property (e.g. `phones.kind`, `socialProfiles.network`). Validation SHALL resolve the sub-property's declared type from the array property's `items.properties` schema and apply the same operator/type matrix used for a plain field. Evaluation SHALL treat the leaf as satisfied when ANY element of the entity's array has a `subProp` value satisfying the operator against the rule's value — a projection across the array, not a literal lookup of the dotted string as a key. A dotted field whose parent is not declared as an array, or whose named sub-property is not declared on the array's item schema, SHALL be rejected the same way an unknown top-level field is.

#### Scenario: A dotted field validates against the array item's sub-schema

- **GIVEN** the `contact` schema declares `phones` as an array of objects with an item property `kind` of type `string`
- **WHEN** a rule leaf `{field: "phones.kind", operator: "equals", value: "mobile"}` is validated
- **THEN** validation SHALL succeed

#### Scenario: Evaluation matches when any array element satisfies the leaf

- **GIVEN** a contact entity with `phones: [{kind: "work", value: "+3161..."}, {kind: "mobile", value: "+3162..."}]`
- **WHEN** the rule `{field: "phones.kind", operator: "equals", value: "mobile"}` is evaluated against it
- **THEN** the rule SHALL match, because at least one `phones` entry has `kind: "mobile"`

#### Scenario: A dotted field on a non-array or unknown parent is rejected

- **GIVEN** a rule leaf `{field: "industry.kind", operator: "equals", value: "x"}` where `industry` is a plain string property
- **WHEN** the rule tree is validated
- **THEN** validation SHALL return a "field not declared" error

@e2e exclude the segment rule-builder UI that would let a marketer pick a dotted field is not yet wired to a page (SegmentBuilder/SegmentRuleNode exist as reusable components; the field list is supplied by a future SegmentEditor surface) — this requirement covers the SegmentService validation/evaluation engine only, exercised by PHPUnit (SegmentServiceTest)
