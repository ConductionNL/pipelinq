# Tasks: marketing-campaign-on-client

- [x] 1.1 Schema: `touchpoint.clientId`, `touchpoint.landingPage`; fragment giving `contact` and `client` `firstTouch` and `lastTouch` (read only in forms) Done as fragment `register.d/98-marketing-origin-on-client.json` (touchpoint 1.0.0 to 1.1.0; contact and client gain both touches, `readOnly`).
  - Verify: register import logs no `PARTIAL IMPORT`; `npm run check:schema-l10n` exit 0
- [x] 1.2 `TouchpointService::recordSubmission()` per D1 and D2: first touch written once, last touch replaced, client resolved from the contact The touch also keeps `campaignId`, `form` and `at`; values are trimmed and capped at 255.
  - Verify: PHPUnit `tests/Unit/Service/TouchpointServiceTest.php`: first submission sets both touches, second changes only the last, a contact without a client writes only the contact
- [x] 1.3 `LandingPageFormSubmittedListener::ingest()` calls it with the event's UTM values and page route Landing page is `<portal>/<page route>`; the form is the campaign's name.
  - Verify: PHPUnit on the listener with a real `LandingPageFormSubmittedEvent`
- [x] 1.4 `EnquiryIntakeService`: UTM fields in `SUBMITTER_FIELDS`, capped and trimmed; `recordSubmission()` when the email matches a contact `EnquiryIntakeService` was removed (db7d0b3ef, decision 179): a website form submits into a lead carrying the page's campaign values as `lastTouch`. `SubmittedLeadIntake::recordTouchpoint()` now calls `recordSubmission()` with the lead's client, the form page and the form's name. REQ-MOC-003 amended to that path.
  - Verify: PHPUnit: a 500 character `utmCampaign` is capped; `source` from the payload is still ignored; hydra gates route-auth and route-reachability pass on the diff
- [x] 1.5 How they found us widget and Touchpoints list on ContactDetail and ClientDetail Built as board PqKlant draws it: Form, Landing page and Campaign rows in ClientDetail's Identity card (last touch). No board draws a How they found us widget, the touches on ContactDetail, or a Touchpoints list (REQ-MOC-002), so those are not built (decision 130). Playwright `tests/e2e/client-campaign-origin.spec.ts` written, not run (live pass).
  - Verify: Playwright `tests/e2e/client-campaign-origin.spec.ts` submits an enquiry with `utm_campaign=voorjaar` for a known contact and sees it as last touch on ClientDetail
- [x] 1.6 Embed snippet in `docs/Features/website-enquiry.md` that passes the page's UTM values; Dutch strings No enquiry embed exists any more (decision 179); `docs/Features/marketing.md` gains How they found you. Dutch strings added.
  - Verify: docs build exit 0; `npm run test:l10n` exit 0
