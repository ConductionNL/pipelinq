# Tasks: marketing-campaign-on-client

- [ ] 1.1 Schema: `touchpoint.clientId`, `touchpoint.landingPage`; fragment giving `contact` and `client` `firstTouch` and `lastTouch` (read only in forms)
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [ ] 1.2 `TouchpointService::recordSubmission()` per D1 and D2: first touch written once, last touch replaced, client resolved from the contact
  - Verify: PHPUnit `tests/Unit/Service/TouchpointServiceTest.php`: first submission sets both touches, second changes only the last, a contact without a client writes only the contact
- [ ] 1.3 `LandingPageFormSubmittedListener::ingest()` calls it with the event's UTM values and page route
  - Verify: PHPUnit on the listener with a real `LandingPageFormSubmittedEvent`
- [ ] 1.4 `EnquiryIntakeService`: UTM fields in `SUBMITTER_FIELDS`, capped and trimmed; `recordSubmission()` when the email matches a contact
  - Verify: PHPUnit: a 500 character `utmCampaign` is capped; `source` from the payload is still ignored; hydra gates route-auth and route-reachability pass on the diff
- [ ] 1.5 How they found us widget and Touchpoints list on ContactDetail and ClientDetail
  - Verify: Playwright `tests/e2e/client-campaign-origin.spec.ts` submits an enquiry with `utm_campaign=voorjaar` for a known contact and sees it as last touch on ClientDetail
- [ ] 1.6 Embed snippet in `docs/Features/website-enquiry.md` that passes the page's UTM values; Dutch strings
  - Verify: docs build exit 0; `npm run test:l10n` exit 0
