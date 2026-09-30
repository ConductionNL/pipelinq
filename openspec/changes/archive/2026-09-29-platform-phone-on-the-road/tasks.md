# Tasks: platform-phone-on-the-road

- [x] 1. Add `ContactLink.vue` and the cell widgets, and use them for phone, email and address fields on client, contact and lead detail. Verify: Vitest for each link type and number normalisation.
- [x] 2. Phone layout for `MyWork.vue`: single column, today's calls and follow-ups first, 44 px targets. Verify: Vitest for ordering; Playwright at 390 px width.
- [x] 3. `LogVisitSheet.vue` and the "Log a visit" action on client and lead detail, posting the real contact moment payload. Verify: the exact payload validated against the real `ticket` schema fragment; Vitest for the follow-up date.
- [x] 4. Reflow client detail, lead detail and the board at phone width. Verify: Playwright asserts `scrollWidth <= clientWidth` on each page at 390 px. Done 2026-09-29: My work and the board wrap at 600 px; the checks are in tests/e2e/spec-coverage/phone-on-the-road.spec.ts, which runs in CI and nightly, not from the lane clone.
- [x] 5. `nl` and `en` strings through the writing skill; axe on the sheet. Strings done; the sheet is an NcDialog with labelled fields. The axe run belongs to the Playwright job.
- [x] 6. On archive add the `mobile-experience` main spec and set `plat-phone` to `built`.

