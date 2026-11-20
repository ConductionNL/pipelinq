# Tasks: screen-pop-incoming-call

## 1. The call on my line
- [x] 1.1 `GET /api/cti/incoming-call` answers `{enabled, delayMs, call}` for the signed-in agent
  - spec_ref: `openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012`
  - Built: `lib/Service/Cti/IncomingCallFinder.php`, `IncomingCallController::show()` (its own controller: `CtiController` is at its complexity limit), route in `appinfo/routes.php`. `TicketService::findByType()` takes an optional order, so the finder reads the agent's five newest telephone contact moments and not their whole history.
  - Tests: `tests/Unit/Service/Cti/IncomingCallFinderTest.php` (no platform and a switched-off screen pop read no ticket; ended, stale, outbound and a colleague's call are not incoming; the fields it filters on exist in the composed ticket schema, with a control), `IncomingCallControllerTest` (the route names the method, the uid is the session's, 401, 403), `TicketServiceTest` (the order reaches the store; without one the query is unchanged).

## 2. The card
- [x] 2.1 `src/components/IncomingCallCard.vue`, mounted in `src/App.vue`: number, queue, line and a timer; one match, several matches or none
  - Built: the card calls `ctiApi.screenPop()` with the number of the call, mounts `ScreenPopModal` for several matches and `NewContactIntakeModal` for a new contact. Live events on tickets are a hint to ask again; a poll every 15 seconds covers an instance without live updates.
  - Tests: `tests/vitest/incomingCallCard.spec.js`, from the answer of the endpoint to the record that opens, and the mount in `App.vue`.
- [x] 2.2 Opening a record links the call's contact moment to it; a new contact is stored first
  - Built: `openMatch()`, `createContact()` and `linkCall()` in the card, through the object store. A failed link never blocks opening the record.
- [x] 2.3 The three facts the board draws under one match: open requests and cases, last contact, what is waiting
  - Built: for one match the card reads the client's 360 summary (`GET /api/customer-360/summary`, which checks that the agent may read the client): Open is its open requests, complaints and meldingen (contact moments are logged interactions, not open work), Last contact is the summary's last activity, Waiting is the new `awaitingCustomerCount`, its requests, complaints and meldingen in status `awaiting_customer`. A contact match reads the summary of the client it belongs to; a contact without a client, several matches or a refused read show no facts, and the card still names the caller.
  - Files: `lib/Service/Customer360SummaryService.php`, `src/components/IncomingCallCard.vue`, `src/services/ctiApi.js`, `l10n/{de,es,fr,it}.json`
  - Tests: `tests/Unit/Service/Customer360SummaryServiceTest.php` (`testAwaitingCustomerCountsTheClientsWaitingTickets`, `testAwaitingCustomerRechecksEveryRow`), `tests/vitest/incomingCallCard.spec.js` ("the three facts under one match")
- [x] 2.4 Strings in six locales; the chooser's title loses its dash and follows the board ("Inkomend gesprek: kies contact")
- [x] 2.5 `docs/Integrations/cti-adapter-setup.md`: what the agent sees

## 3. Live
- [x] 3.1 (live pass, decision 139) `tests/e2e/incoming-call-card.spec.ts`: an install without a telephony platform shows no card and the endpoint answers `enabled: false` (written, not run)
  - Live pass 16 Nov 2026: `incoming-call-card.spec.ts` passed on a clean install without telephony.
- [ ] 3.2 (live pass, decision 139) With a platform configured: post a signed `ringing` webhook for an agent's extension, see the card within 15 seconds, open the match, see `client` on the contact moment; post `ended`, see the card go
