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
- [ ] 2.3 The three facts the board draws under one match: open requests and cases, last contact, what is waiting (not built: the lookup returns no counts; needs a summary read per match)
- [x] 2.4 Strings in six locales; the chooser's title loses its dash and follows the board ("Inkomend gesprek: kies contact")
- [x] 2.5 `docs/Integrations/cti-adapter-setup.md`: what the agent sees

## 3. Live
- [ ] 3.1 (live pass, decision 139) `tests/e2e/incoming-call-card.spec.ts`: an install without a telephony platform shows no card and the endpoint answers `enabled: false` (written, not run)
- [ ] 3.2 (live pass, decision 139) With a platform configured: post a signed `ringing` webhook for an agent's extension, see the card within 15 seconds, open the match, see `client` on the contact moment; post `ended`, see the card go
