## Context

`klantbeeld-360-activation` (merged 2026-07-12) shipped `KlantbeeldSummaryService`,
`KlantbeeldController`, route `GET /api/klantbeeld/summary`, and spec capability
`openspec/specs/klantbeeld-360/`. Pipelinq's naming convention is English-canonical
for all code/spec/API surfaces, with Dutch reserved for user-facing i18n/l10n
strings. This change is a pure rename to align the shipped surface with that
convention — no behavior, RBAC, or aggregation-logic change.

## Goals / Non-Goals

**Goals:**
- Rename `KlantbeeldSummaryService` → `Customer360SummaryService`, `KlantbeeldController` → `Customer360Controller` (class + file, via `git mv`)
- Rename route `GET /api/klantbeeld/summary` → `GET /api/customer-360/summary`
- Rename spec capability `openspec/specs/klantbeeld-360/` → `openspec/specs/customer-360/`
- Update every live reference (manifest endpoint URLs, tests, docs, `@spec` tags, the one other in-flight change that targets this capability)

**Non-Goals:**
- No change to the aggregation logic, RBAC read-guard, or doelbinding access-log entry in `KlantbeeldSummaryService`/`Customer360SummaryService`
- No change to any user-facing English string — the manifest's stat-widget titles ("Open matters", "SLA breached", "SLA at risk", "Active queues", "Last activity") are already English; there is no Dutch-language UI label literally reading "Klantbeeld" to translate. l10n catalogs (`l10n/*.json`) are unaffected — no translatable string changes
- No OpenRegister schema/data changes — this only touches code surface naming
- Not touching `openspec/changes/archive/2026-03-21-klantbeeld-360/`, `archive/2026-06-14-klantbeeld-360/`, or `archive/2026-07-12-klantbeeld-360-activation/` — these are immutable history

## Decisions

- **Route path** `/api/customer-360/summary` (hyphenated, matching the capability slug `customer-360`) rather than `/api/customer360/summary` — consistent with Pipelinq's existing kebab-case route conventions (e.g. `/api/billing/handoff/{clientId}`).
- **Class name** `Customer360SummaryService`/`Customer360Controller` (no separating underscore/hyphen — PHP class names can't contain hyphens) rather than spelling out `CustomerThreeSixty*` — `360` as a literal digit suffix matches the existing `ClientDetail` "Client 360" terminology already used in the manifest's declarative page notes and CHANGELOG.
- **Spec delta bookkeeping**: the OpenSpec `spec-driven` schema has no "rename capability directory" delta primitive (only `RENAMED Requirements` for individual requirement headers within one spec file). The delta in this change's `specs/klantbeeld-360/spec.md` uses `MODIFIED Requirements` to rename the four MVP requirement titles/prose from "Klantbeeld" to "Customer 360" wording; the physical `git mv openspec/specs/klantbeeld-360/ openspec/specs/customer-360/` is performed directly as an implementation task (task list), not via automated delta sync, and the change is archived under the new `customer-360` spec path.
- **`customer-satisfaction-closed-loop` in-flight change**: its `specs/klantbeeld-360/` delta directory (targeting this capability) is renamed alongside ours (`git mv` → `specs/customer-360/`) and its prose references to `klantbeeld-360`/`klantbeeld` updated, so the capability name stays consistent for whichever change lands second. This is a mechanical rename only — its own requirements/scope are untouched.

## Risks / Trade-offs

- **[Risk]** Renaming a merged, deployed route (`/api/klantbeeld/summary` → `/api/customer-360/summary`) could 404 any client that cached the old URL → **Mitigation**: the endpoint is same-origin/same-session (bound only from `src/manifest.json`'s stat widgets, rebuilt with the app), not a published/versioned external API; no external consumers exist per the shipped spec's Appendix.
- **[Risk]** Touching a second, unrelated in-flight change's files (`customer-satisfaction-closed-loop`) could collide with parallel work on that change → **Mitigation**: limited to the mechanical `klantbeeld-360`→`customer-360` substring rename in its delta spec dir name and prose references; no requirement/scope edits.
- **[Risk]** Stale `@spec` tags in `src/App.vue`, `LeadProbabilityCell.vue`, `LeadCloseDateCell.vue` already point at a non-existent unarchived `openspec/changes/klantbeeld-360/` path (pre-existing drift, not introduced by this change) → **Mitigation**: normalize the `klantbeeld-360` substring to `customer-360` for consistency; deeper anchor-path forensics (finding which archived change these really belong to) is out of scope for a pure-rename change.

## Migration Plan

1. `git mv` the two PHP files + two test files, update class names/namespaced references
2. Update `appinfo/routes.php` route name/path
3. Update `src/manifest.json` stat-widget endpoint URLs (5 occurrences)
4. `git mv openspec/specs/klantbeeld-360/ openspec/specs/customer-360/`, update requirement titles/prose
5. Update docs (`docs/Features/klantbeeld-360.md` → `customer-360.md`), `CHANGELOG.md`
6. Update the `customer-satisfaction-closed-loop` in-flight change's delta spec dir + prose references
7. Baseline (before) vs after: `phpunit --configuration phpunit-unit.xml`, scoped `phpcs` on touched `lib/` files — zero new failures
8. Archive this change; the live spec now lives at `openspec/specs/customer-360/spec.md`

No rollback beyond `git revert` — this is a same-deploy-cycle rename, not a phased migration.

## Open Questions

None — this is a mechanical, low-ambiguity rename.
