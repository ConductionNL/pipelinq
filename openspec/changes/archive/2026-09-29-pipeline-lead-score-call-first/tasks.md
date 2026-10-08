# Tasks: pipeline-lead-score-call-first

- [x] 1. Add `LeadScoreCell.vue`, register `lead-score` in `src/App.vue`, add the column and the "Call first" quick filter to the `/leads` page in `src/manifest.json`. Verify: Vitest on the cell (band text, dash for null); `npm run check:manifest-parity`.
- [x] 2. Add the score badge to `PipelineCard.vue` and the `score` sort to the board table headers. Verify: Vitest on both.
- [x] 3. Add `LeadScoreExplanation.vue` and the drift test against the real calculation expression. Verify: Vitest with four fixture leads.
- [x] 4. `nl` and `en` strings through the writing skill; axe on the badge and popover. Done 2026-09-29: strings in en and nl; the badge is a native button whose accessible name carries the number and the band, the band is also printed as a word. The axe run belongs to the Playwright job (not runnable from the lane clone).
- [x] 5. Playwright: create three leads with different fields, open "Call first", see them in score order. Written as tests/e2e/spec-coverage/lead-score-call-first.spec.ts; it runs in CI and nightly, not from the lane clone.
- [x] 6. On archive fold the delta into `openspec/specs/lead-management/spec.md`, correct the `lead-scoring` matrix note, and set the row to `built`.

- [x] 0. Before archive: the main spec `openspec/specs/lead-management/spec.md` has requirement headers outside its `## Requirements` section (lines 677 and later), so `openspec validate` reports that archive would refuse this delta. Move them inside the section first. Verify: `openspec validate pipeline-lead-score-call-first --strict` prints no archive-refusal note.
