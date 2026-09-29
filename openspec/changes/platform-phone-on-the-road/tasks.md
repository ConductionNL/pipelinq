# Tasks: platform-phone-on-the-road

- [ ] 1. Add `ContactLink.vue` and the cell widgets, and use them for phone, email and address fields on client, contact and lead detail. Verify: Vitest for each link type and number normalisation.
- [ ] 2. Phone layout for `MyWork.vue`: single column, today's calls and follow-ups first, 44 px targets. Verify: Vitest for ordering; Playwright at 390 px width.
- [ ] 3. `LogVisitSheet.vue` and the "Log a visit" action on client and lead detail, posting the real contact moment payload. Verify: the exact payload validated against the real `ticket` schema fragment; Vitest for the follow-up date.
- [ ] 4. Reflow client detail, lead detail and the board at phone width. Verify: Playwright asserts `scrollWidth <= clientWidth` on each page at 390 px.
- [ ] 5. `nl` and `en` strings through the writing skill; axe on the sheet.
- [ ] 6. On archive add the `mobile-experience` main spec and set `plat-phone` to `built`.

