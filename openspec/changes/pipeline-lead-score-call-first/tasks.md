# Tasks: pipeline-lead-score-call-first

- [ ] 1. Add `LeadScoreCell.vue`, register `lead-score` in `src/App.vue`, add the column and the "Call first" quick filter to the `/leads` page in `src/manifest.json`. Verify: Vitest on the cell (band text, dash for null); `npm run check:manifest-parity`.
- [ ] 2. Add the score badge to `PipelineCard.vue` and the `score` sort to the board table headers. Verify: Vitest on both.
- [ ] 3. Add `LeadScoreExplanation.vue` and the drift test against the real calculation expression. Verify: Vitest with four fixture leads.
- [ ] 4. `nl` and `en` strings through the writing skill; axe on the badge and popover.
- [ ] 5. Playwright: create three leads with different fields, open "Call first", see them in score order.
- [ ] 6. On archive fold the delta into `openspec/specs/lead-management/spec.md`, correct the `lead-scoring` matrix note, and set the row to `built`.

- [ ] 0. Before archive: the main spec `openspec/specs/lead-management/spec.md` has requirement headers outside its `## Requirements` section (lines 677 and later), so `openspec validate` reports that archive would refuse this delta. Move them inside the section first. Verify: `openspec validate pipeline-lead-score-call-first --strict` prints no archive-refusal note.
