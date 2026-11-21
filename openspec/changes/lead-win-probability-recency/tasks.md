# Tasks: lead-win-probability-recency

## 1. nextcloud-vue (shared)
- [x] 1.1 `useListView.js` and `useDetailView.js`: read an `extend` array from the page config and pass it as `_extend[]` on the OpenRegister object requests, as `CnWidgetObjectTable` already does with `source.extend` (ADR-049); unit tests in nextcloud-vue (V1)
  - Already in @conduction/nextcloud-vue 2.181.0 (pipelinq's lock): `useListView` forwards `extend` as `_extend[]`, `CnIndexPage` takes `extend` and the detail renderer reads `config.extend`; nothing to build here.
- [x] 1.2 Document `extend` in the manifest schema for `type: "index"` and `type: "detail"`; release, then bump pipelinq (V1)
  - `extend` is documented for index and detail pages in nextcloud-vue's `app-manifest-v2.schema.json`; pipelinq's `^2.180.0` already resolves to 2.181.0.

## 2. pipelinq manifest
- [x] 2.1 `src/manifest.json` Leads index: `"extend": ["calculations"]`; the column at `key: qualificationScore, label: Win chance` becomes `key: winProbability` with the `lead-probability` widget; add or keep the lead score as its own column labelled "Lead score" if PqLeads draws it (spec_ref `openspec/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail`) (V1)
  - `LeadList.vue` (the page renders this component, not the manifest columns) reads `extend: ['client', 'calculations']`; Win chance is `winProbability` (the board's colour-banded `progress` bar, falling back to `probability`, keeping the out-of-date badge); the lead score keeps its own sortable "Lead score" column with the `lead-score` widget, as REQ-LSCORE-001 requires. The manifest's Leads config says the same, with `lead-probability`. The client and contact lead lists ask for `calculations` and read `winProbability` too. Tests: `tests/vitest/leadWinProbabilitySurfaces.spec.js`, `leadScoreBadge.spec.js`, `leadsBoardLine.spec.js`.
- [x] 2.2 LeadDetail: `"extend": ["calculations"]`, Deal data shows `winProbability` beside value, probability and status (V1)
  - LeadDetail `extend: ["calculations"]`; the Deal data and the side Win chance card read `winProbability` (read-only); `leadPageBoard.spec.js`.
- [x] 2.3 Pipeline board lead cards: request `calculations` and show the win chance on the card as PqPipeline draws it (V1)
  - `PipelineBoard.fetchSchemaItems` adds `_extend[]=calculations` on the lead read; `PipelineCard.chance` shows `winProbability`, else the lead's probability, else the stage's; `pipelineBoardLogic.spec.js`, `pipelineBoardRendered.spec.js`, `leadWinProbabilitySurfaces.spec.js`.
- [x] 2.4 l10n nl and en for any changed label (V1)
  - "Lead score" in en and nl (`l10n/*.json`, rebuilt `.js`); "Win chance" was already translated.

## 3. Seed and tests
- [x] 3.1 Seed leads that render distinct bands on a fresh install: since `@self.updated` cannot be written by a client, seed through the repair step with OpenRegister's import keeping the given `updated` timestamp, or document in the spec that the bands show only after time passes and drop the seed requirement (decide in the PR) (V1)
  - Decided: document, not seed. `@self.updated` cannot be written by a client or import, so the spec delta now says the seeds read their full probability on a fresh install and decay as they age; the bands are proven against the real expression in `tests/vitest/leadWinProbabilityBands.spec.js`.
- [ ] 3.2 Remove the `@e2e exclude` defect record on the surfacing requirement and turn `tests/e2e/spec-coverage/lead-scoring-win-probability.spec.ts` into a real test: the Leads index Win chance cell is a number for a seeded lead (V1)
  - Written, not yet run (needs a live instance): `tests/e2e/spec-coverage/lead-scoring-win-probability.spec.ts` now asserts the served `winProbability` in the Leads Win chance column and on the lead page. The delta's surfacing requirement carries no `@e2e exclude`.
- [ ] 3.3 Flip `lead-win-probability` to built in `openspec/parity/capabilities.json` and close pipelinq#774 (V1)
