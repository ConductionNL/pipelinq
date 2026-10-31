# Tasks: contact-moments-phone-lookup

- [x] 1.1 (`lib/Service/PhoneNeedles.php`, `tests/Unit/Service/PhoneNeedlesTest.php`; `CtiContactMatcherTest` unchanged and green; an instance method, since phpmd refuses static access) Extract `needles()` into a public `PhoneNeedles::for()` used by `CtiContactMatcher`
  - Verify: existing `CtiContactMatcherTest` passes unchanged; new PHPUnit on `PhoneNeedles`
- [x] 1.2 (`lib/Settings/register.d/99-zzzzz-ticket-phone.json`, `visible: false` and not facetable, since a facet of phone numbers is noise and the filter needs no facet. One `TicketPhoneListener` on creating and updating sets it for every writer instead of one edit per writer: CTI `from_number`/`to_number` by direction, and `reporterPhone`, the number a resident typed on a web form. `tests/Unit/Listener/TicketPhoneListenerTest.php` per source. Import log check waits for the live pass) Add `ticket.phoneE164` (facetable, hidden in forms); set it on CTI, manual contact moment and enquiry-to-ticket writes
  - Verify: PHPUnit per writer; register import logs no `PARTIAL IMPORT`
- [x] 1.3 (`lib/Repair/BackfillTicketPhoneNumbers.php` over `PhoneHistoryService::backfillTicketPhones()`, pages of 200; `PhoneHistoryServiceTest::testTheBackfillRunsTwiceAndWritesOnce`) Repair step filling `phoneE164` from `from_number`, idempotent and batched; registered in `appinfo/info.xml`
  - Verify: PHPUnit running the step twice, second run changes nothing
- [x] 1.4 (`TicketPhoneController::byPhone`, `PhoneHistoryServiceTest::testANumberIsFoundHoweverItWasTyped` with all three typings plus spaces. The search matches the stored E.164 only: once the back-fill has run every stored number is E.164, so the last-nine-digits fallback of design D3 finds nothing more and is not built) `lib/Service/PhoneHistoryService.php::findTickets()` and `GET /api/tickets/by-phone`
  - Verify: PHPUnit with numbers typed as `06-12345678`, `+31 6 1234 5678`, `0031612345678` all finding one ticket; hydra gates route-auth and route-reachability pass on the diff
- [x] 1.5 (`tests/vitest/newContactIntakeHistory.spec.js`; the Playwright verify waits for the live pass) Earlier contacts list in `src/modals/NewContactIntakeModal.vue`
  - Verify: Playwright `tests/e2e/phone-history.spec.ts` fires a CTI event from an unknown number with two earlier contact moments and sees both in the intake modal
- [x] 1.6 Tickets list phone search with the "Searching by phone number" line
  - Verify: Playwright searches `06 1234 5678` and finds a ticket stored as `+31612345678`
  - Built as the registry handler `ticketPhoneSearch` (`src/utils/ticketPhoneSearch.js`) named by the Tickets page's `config.searchHandler`; it needs nextcloud-vue's `searchHandler` (change `index-search-handler`, schema 2.170.0) released and the dependency bumped. Vitest `tests/vitest/ticketPhoneSearch.spec.js`; the Playwright run waits for the live pass
- [ ] 1.7 Dutch strings and a section in `docs/Features/telephony.md` (done for 1.5 in en and nl, section in `docs/Features/contactmomenten.md` since there is no telephony.md; the Tickets search line "Searching by phone number" / "Zoeken op telefoonnummer" added with 1.6)
  - Verify: `npm run test:l10n` exit 0; docs build exit 0
