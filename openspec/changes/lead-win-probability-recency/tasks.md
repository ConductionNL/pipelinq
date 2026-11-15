# Tasks: lead-win-probability-recency

## 1. nextcloud-vue (shared)
- [ ] 1.1 `useListView.js` and `useDetailView.js`: read an `extend` array from the page config and pass it as `_extend[]` on the OpenRegister object requests, as `CnWidgetObjectTable` already does with `source.extend` (ADR-049); unit tests in nextcloud-vue (V1)
- [ ] 1.2 Document `extend` in the manifest schema for `type: "index"` and `type: "detail"`; release, then bump pipelinq (V1)

## 2. pipelinq manifest
- [ ] 2.1 `src/manifest.json` Leads index: `"extend": ["calculations"]`; the column at `key: qualificationScore, label: Win chance` becomes `key: winProbability` with the `lead-probability` widget; add or keep the lead score as its own column labelled "Lead score" if PqLeads draws it (spec_ref `openspec/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail`) (V1)
- [ ] 2.2 LeadDetail: `"extend": ["calculations"]`, Deal data shows `winProbability` beside value, probability and status (V1)
- [ ] 2.3 Pipeline board lead cards: request `calculations` and show the win chance on the card as PqPipeline draws it (V1)
- [ ] 2.4 l10n nl and en for any changed label (V1)

## 3. Seed and tests
- [ ] 3.1 Seed leads that render distinct bands on a fresh install: since `@self.updated` cannot be written by a client, seed through the repair step with OpenRegister's import keeping the given `updated` timestamp, or document in the spec that the bands show only after time passes and drop the seed requirement (decide in the PR) (V1)
- [ ] 3.2 Remove the `@e2e exclude` defect record on the surfacing requirement and turn `tests/e2e/spec-coverage/lead-scoring-win-probability.spec.ts` into a real test: the Leads index Win chance cell is a number for a seeded lead (V1)
- [ ] 3.3 Flip `lead-win-probability` to built in `openspec/parity/capabilities.json` and close pipelinq#774 (V1)
