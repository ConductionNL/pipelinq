# Design — spec-anchor-repair (pipelinq)

## Summary

2679 broken `@spec` anchors on base `origin/development`. **711** were
auto-repointed to canonical `openspec/specs/` targets (202 to an exact
requirement heading, 509 to the capability spec file); **1968** could not be
resolved unambiguously and are flagged for human triage in
`residual-dangling.md`. See the OpenRegister change design for the full tool
description; the same `tool/resolver.py` + `tool/repoint.py` + `tool/test_repoint.py`
(passing) are used here unchanged.

## Conservatism rules (why this is safe to script)

The repointer touches only docblock `@spec` comment tags. It recovers the
capability **verbatim** from the archived `tasks.md` task line, uses a
requirement-level anchor **only** on an exact heading-text match (else drops to
capability-level — an honest downgrade, never a positional guess), and re-checks
every proposed target with gate-46 logic **before** writing (rejects any that
would not resolve). Anything not covered → DANGLING, filed for human triage.

## Comment-only proof

- `git diff --unified=0`: every `+`/`-` line contains `@spec` (pipelinq: 0 non-`@spec` lines).
- `git diff --numstat`: every file has `insertions == deletions` (1:1 rewrite; 0 asymmetric).
- `tool/test_repoint.py`: logic byte-identical + dangling anchors left untouched.

## Non-goals

Repointing the 1968 residual-dangling anchors (human triage); re-heading canonical
specs; authoring new requirements.
