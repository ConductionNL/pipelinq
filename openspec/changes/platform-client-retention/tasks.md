# Tasks: platform-client-retention

## 1. The relationship end date

- [ ] 1.1 Add `relationshipEndedAt` (string, date-time) to the client schema in `lib/Settings/register.d/15-unify-client-contact.json`, not editable in forms
  - Verify: `npm run check:schema-l10n` exit 0; PHPUnit `tests/Unit/Settings/RegisterAnnotationsTest.php` finds the property
- [ ] 1.2 Declare `x-openregister-lifecycle` on the client with `field: accountStatus` and transitions between active, inactive and blocked; the transition to inactive runs `set-fields` with `relationshipEndedAt: "@now"`, the one back to active sets it to null
  - Verify: on a local instance, setting a client inactive stamps the date and setting it active clears it (Newman or Playwright)
- [ ] 1.3 Add the `archive` block to the client: `enabled`, `afleidingswijze: ander_datumkenmerk`, `sourceDateProperty: relationshipEndedAt`, `defaultBewaartermijn: P2Y`, `defaultNominatie: vernietigen`
  - Verify: a client made inactive shows `@self.retention.archiefactiedatum` two years after `relationshipEndedAt`; an active client shows none. Depends on the OpenRegister recalculation change (design D3)

## 2. Contact persons

- [ ] 2.1 Add the `archive` block to the contact schema with a relation method, `sourceRelation: client`, `sourceRelationProperty: relationshipEndedAt`
  - Verify: a contact person of an inactive client carries the same destruction date; a contact with no client carries none

## 3. Review and back-fill

- [ ] 3.1 Add the custom page RetentionReview (`/retention-review`) and place it in the settings section in `src/menu-layout.json`; list destruction lists holding clients or contact persons, with the number of tickets and leads that reference each client
  - Verify: `npm run check:manifest` exit 0; Vitest mounts the page with a mocked list and shows only client and contact entries
- [ ] 3.2 Approve and Reject call `POST /apps/openregister/api/archival/destruction-lists/{id}/approve` and `.../reject`, with a confirm dialog in `src/modals/`
  - Verify: Playwright `tests/e2e/client-retention.spec.ts` approves a seeded list and the client is gone after OpenRegister's execution job runs
- [ ] 3.3 Add repair step `lib/Repair/BackfillClientRetention.php` (lazy OpenRegister resolve, skips when OpenRegister is absent) and register it in `appinfo/info.xml`
  - Verify: PHPUnit runs it twice over a fake service and applies metadata once per record

## 4. Anonymisation profile (gated on OpenRegister)

- [ ] 4.1 Once OpenRegister reads a profile beside the `archive` block, declare it on client and contact with the properties named in design D6
  - Verify: OpenRegister's planner accepts both profiles (a profile naming an unknown property is refused, so a green import is the check)

## 5. Text and docs

- [ ] 5.1 English strings and Dutch translations for the review page and the new property, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 5.2 Admin doc `docs/Features/client-retention.md`: when the clock starts, who approves, and what happens to contact persons
  - Verify: docs build exit 0
