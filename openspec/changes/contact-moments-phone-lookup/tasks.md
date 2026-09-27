# Tasks: contact-moments-phone-lookup

- [ ] 1.1 Extract `needles()` into a public `PhoneNeedles::for()` used by `CtiContactMatcher`
  - Verify: existing `CtiContactMatcherTest` passes unchanged; new PHPUnit on `PhoneNeedles`
- [ ] 1.2 Add `ticket.phoneE164` (facetable, hidden in forms); set it on CTI, manual contact moment and enquiry-to-ticket writes
  - Verify: PHPUnit per writer; register import logs no `PARTIAL IMPORT`
- [ ] 1.3 Repair step filling `phoneE164` from `from_number`, idempotent and batched; registered in `appinfo/info.xml`
  - Verify: PHPUnit running the step twice, second run changes nothing
- [ ] 1.4 `lib/Service/PhoneHistoryService.php::findTickets()` and `GET /api/tickets/by-phone`
  - Verify: PHPUnit with numbers typed as `06-12345678`, `+31 6 1234 5678`, `0031612345678` all finding one ticket; hydra gates route-auth and route-reachability pass on the diff
- [ ] 1.5 Earlier contacts list in `src/modals/NewContactIntakeModal.vue`
  - Verify: Playwright `tests/e2e/phone-history.spec.ts` fires a CTI event from an unknown number with two earlier contact moments and sees both in the intake modal
- [ ] 1.6 Tickets list phone search with the "Searching by phone number" line
  - Verify: Playwright searches `06 1234 5678` and finds a ticket stored as `+31612345678`
- [ ] 1.7 Dutch strings and a section in `docs/Features/telephony.md`
  - Verify: `npm run test:l10n` exit 0; docs build exit 0
