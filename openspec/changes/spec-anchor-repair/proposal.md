---
kind: config
---

## Why

A fleet audit of `@spec openspec/...` traceability anchors (hydra gate-46
`spec-anchor-existence`) found **2679 broken anchors in pipelinq**. A broken
anchor is a docblock/JSDoc `@spec` tag whose target file or `#requirement`
heading no longer resolves, so the code *claims* to implement a requirement the
tag cannot point at. The dominant cause is mechanical: `/opsx-annotate` runs
tagged methods with `@spec openspec/changes/<slug>/tasks.md#task-N` pointing at
the change dir, not canonical `openspec/specs/`; when the change was archived the
target evaporated. The intended capability is recoverable verbatim from the
archived `tasks.md` task line.

## What Changes

A **deterministic, comment-only** repointer (`tool/`) rewrites every
unambiguously-resolvable broken `@spec` anchor to its canonical
`openspec/specs/<cap>/spec.md[#requirement-<slug>]` target, and flags everything
else for human triage rather than guessing. Applied to pipelinq (base
`origin/development`):

- **711 anchors auto-repointed** across 174 files (202 anchor-level, 509 file-level).
- **1968 anchors left dangling** and filed for human review (`residual-dangling.md` + umbrella issue).
- **Comment-only proof**: every changed line contains `@spec`; every touched file
  has `insertions == deletions` (1:1 line rewrite); no logic byte changes.
- **Gate-46 re-verify**: repointed anchors resolve — pipelinq broken 2679 → 1968.

Ships only docblock `@spec` retargets + the tool, its unit test, and the triage
list. No runtime behaviour changes.

## Impact

- Affected: docblock `@spec` tags in `lib/` and `src/` only.
- Risk: negligible — comment-only, mechanically proven, gate-46-verified.
- Follow-up: the 1968 residual-dangling anchors (umbrella issue). Tool's canonical
  home is `hydra/scripts/` for fleet reuse.
