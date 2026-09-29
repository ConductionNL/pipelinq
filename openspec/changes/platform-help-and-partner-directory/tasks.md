# Tasks: platform-help-and-partner-directory

- [ ] 1. `lib/Service/HelpContentService.php` reading and validating the two lists (https links only, size caps, defaults). Verify: PHPUnit with valid, oversized and non-https input.
- [ ] 2. `HelpController` with `GET /api/help` (any signed-in user) and `PUT /api/help` (admin only, auth attribute matching the semantic), routes registered. Verify: PHPUnit for both roles; route-auth, semantic-auth and IDOR gates.
- [ ] 3. `HelpView.vue` with the three parts, province and service filters (`NcSelect` with input labels), the walkthrough replay, and hidden empty sections; add the manifest page and menu entry. Verify: Vitest for empty, courses only, partners only; `npm run check:manifest-parity`.
- [ ] 4. Administration tab to add, edit, reorder and remove entries. Verify: Vitest; the exact payload accepted by the real service.
- [ ] 5. `nl` and `en` strings through the writing skill; axe on the page.
- [ ] 6. On archive add the `onboarding` main spec from the delta and set `vendor-training` and `local-partner` to `built`, with a note that content is supplied by the vendor.

