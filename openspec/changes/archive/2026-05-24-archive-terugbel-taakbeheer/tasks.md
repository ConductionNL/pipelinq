# Tasks: Archive terugbel-taakbeheer

## Cleanup tasks

- [x] Compare `terugbel-taakbeheer` REQs against `callback-management` and `my-work`.
- [x] Identify unique REQs (4 of 9) and append them to `callback-management/spec.md` with merge-source attribution.
- [x] Delete `openspec/specs/terugbel-taakbeheer/`.
- [x] Delete pending duplicate change `openspec/changes/2026-03-20-terugbel-taakbeheer/`.
- [x] Document analysis in this change's `proposal.md` and `specs/callback-management/spec.md` delta.

## Validation

- [x] `npx openspec validate --strict` (run before commit, see commit body for result).

## Out of scope (filed elsewhere)

- Implementing the 4 newly-merged V1 REQs in callback-management.
- Reverse-spec or annotation passes for the remaining 4 capabilities flagged in coverage-report bucket 3b (klantbeeld-360, pipeline-insights, product-catalog-quoting, kcc-werkplek).
