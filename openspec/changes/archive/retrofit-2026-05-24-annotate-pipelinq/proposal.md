# Retrofit — annotate pipelinq against existing specs

Retroactive annotation of 156 methods across 68 files against 65 REQs in 20 capabilities. No code logic changes. No spec deltas (all REQs already exist in `openspec/specs/`).

Source: `openspec/coverage-report.md` generated 2026-05-24 (Bucket 1 only).

Skipped:
- 1 Bucket 1 entry against `terugbel-taakbeheer` (spec being archived in PR #546).
- 21 Bucket 1 entries flagged `needs_review: true` (require human disambiguation before annotation).
- 0 entries already carried canonical `@spec` tags in code (44 methods reported in the `annotated` bucket are not re-tagged).

See [retrofit playbook](../../../../.github/docs/claude/retrofit.md).
