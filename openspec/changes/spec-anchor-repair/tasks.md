# Tasks — spec-anchor-repair (pipelinq)

- [x] task-1: Measure broken `@spec` anchors repo-wide with gate-46 resolution logic (2679 on base).
- [x] task-2: Categorise broken anchors by cause (archived→canonical-recoverable vs genuinely-dangling).
- [x] task-3: Apply the deterministic comment-only repointer (`tool/repoint.py`) — 711 repointed across 174 files (202 anchor-level, 509 file-level).
- [x] task-4: Comment-only proof — 0 non-`@spec` changed lines; 0 files with asymmetric insertions/deletions.
- [x] task-5: Gate-46 re-verify — broken 2679 → 1968 (all repointed anchors resolve).
- [x] task-6: File the 1968 residual-dangling anchors for human triage (`residual-dangling.md` + umbrella issue).
- [ ] task-7: STALE-BASE GUARD before push — `git diff --numstat origin/development` is `@spec`-lines-only.
- [ ] task-8: PR to `development`, admin-merge, archive change.
