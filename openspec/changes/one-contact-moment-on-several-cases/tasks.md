# Tasks: one-contact-moment-on-several-cases

## Phase 0: Schema

- [x] 0.1 Widen `caseReference` on the contact moment facet to an ordered set of ADR-048 semantic references, minimum one, no duplicates (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002`
- [x] 0.2 Add `primaryCaseReference`, constrained to a member of the set (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-one-reference-is-the-primary-one-and-it-is-named-req-cms-002`
- [x] 0.3 Leave `request` on the single reference and say why in the schema description (V1)

## Phase 1: Migration

- [x] 1.1 Add `Repair\WidenContactMomentCaseReference`, idempotent, wrapping a string value in a one-element array and setting it as primary; register in `appinfo/info.xml` (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002`

## Phase 2: Reading

- [x] 2.1 Change the leaf's host filter from equality to membership, bounded per ADR-058 (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-case-lists-the-contact-moments-it-is-a-member-of-req-cms-003`
- [x] 2.2 Render the shared marker on the panel, resolving other cases by semantic reference and falling back to a count where the reader may not see them (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-shared-contact-moment-says-so-before-it-is-edited-req-cms-005`

## Phase 3: Writing

- [x] 3.1 Add `fileOnAlsoCase`, appending a reference with its actor and timestamp (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-filing-onto-a-further-case-is-an-act-not-a-copy-req-cms-004`
- [x] 3.2 Add `unfileFromCase`, refusing to empty the set and refusing to orphan the primary (V1)
  - **spec_ref**: `specs/contactmomenten/spec.md#requirement-a-contact-moment-cannot-be-left-with-no-case-req-cms-006`

## Phase 4: Quality

- [x] 4.1 PHPUnit: migration idempotence, the two refusals, primary constrained to membership
- [x] 4.2 PHPUnit: the membership filter returns one record to each of three cases, not three records
- [x] 4.3 Playwright `tests/e2e/contact-moment-several-cases.spec.ts` covering file, unfile, the marker and the last-reference refusal
- [x] 4.4 Dutch and English strings, sentence case
- [x] 4.5 Hand the widened contract and the two acts to dossiq for its Communication tab
