# Tasks: correspondence-language-per-party

## Phase 0: Schema

- [x] 0.1 Add `correspondenceLanguage` to the party record as an administered typed field, BCP 47, optional, facetable, no default (V1)
  - **spec_ref**: `specs/parties/spec.md#requirement-a-party-carries-the-language-it-asked-to-be-written-in-req-pcl-001`
- [x] 0.2 Derive the selectable set from the locales the instance ships, so the picker cannot offer one nothing renders (V1)
  - **spec_ref**: `specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002`

## Phase 1: The resolver

- [x] 1.1 Add the resolver answering the tag and the rule that produced it, in the three-step order (V1)
  - **spec_ref**: `specs/parties/spec.md#requirement-the-language-to-write-in-resolves-with-its-reason-req-pcl-003`
- [x] 1.2 Publish the resolver as a contract callers reach through the party's semantic type, per ADR-048 (V1)
  - **spec_ref**: `specs/parties/spec.md#requirement-the-resolver-is-published-and-no-caller-reads-the-property-directly-req-pcl-005`

## Phase 2: Surfaces

- [x] 2.1 Show and edit the preference on the party detail and in the party leaf, with unset rendered as unset rather than as the default (V1)
  - **spec_ref**: `specs/parties/spec.md#requirement-the-preference-is-visible-wherever-the-party-is-req-pcl-004`
- [x] 2.2 Add the conflict to the merge: two set values ask, one set value survives (V1)
  - **spec_ref**: `specs/parties/spec.md#requirement-a-merge-does-not-pick-a-language-silently-req-pcl-006`

## Phase 3: Quality

- [x] 3.1 PHPUnit over the resolver: set, unset with an instance default, unset with none, and an unrenderable tag
- [x] 3.2 PHPUnit over the merge conflict, both directions
- [x] 3.3 Playwright `tests/e2e/correspondence-language.spec.ts` covering the picker, the leaf read and the merge prompt
- [x] 3.4 Dutch and English strings, sentence case, per ADR-007 and ADR-025
- [x] 3.5 Hand the resolver's contract to filinq (template variant, ADR-075), integriq (outbound locale) and dossiq (Parties tab)
