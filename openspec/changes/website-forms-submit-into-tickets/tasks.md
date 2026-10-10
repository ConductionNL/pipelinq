# Tasks: website-forms-submit-into-tickets

## 1. Website form into ticket

- [ ] 1.1 Website form submits into `ticket` through `FormSubmitService`; honeypot, rate limit, allowlist and empty check first
  - Spec ref: specs/website-enquiry-intake/spec.md
  - Files: lib/Service/EnquiryIntakeService.php (becomes the website ticket intake), lib/Controller/EnquiryController.php, appinfo/routes.php
  - Test: unit test asserting a ticket and no enquiry; honeypot control
- [ ] 1.2 Ticket reference and `receivedAt` in the answer; same for `PortalRequestService`

## 2. Landing page into lead

- [ ] 2.1 `LandingPageProvisioningService` declares the form with destination `lead`, validated on provisioning
- [ ] 2.2 Move contact matching and the touchpoint from `LandingPageFormSubmittedListener` to a lead-create listener, keyed on the nonce
- [ ] 2.3 Remove `LandingPageFormSubmittedEvent` and its listener once portaliq stops relaying

## 3. public-intake-forms

- [ ] 3.1 Apply the delta: one model, fleet forms with a pipelinq destination; drop the six generic builder requirements (L503 to L585) and the `satisfactionSurvey`/`surveyResponse` retirement contradiction with `customer-satisfaction`, noting survey responses are their own destination
- [ ] 3.2 `omnichannel-registratie` "Unified Inbox": add a note that messages without a form are out of decision 179 per Q5

## 4. Drain and remove

- [ ] 4.1 `occ pipelinq:enquiry:drain` (and `--include-intake-submissions`), report delivered and refused
- [ ] 4.2 Remove `enquiry` (register.d/26-website-enquiry.json), the flow (27-enquiry-to-lead-flow.json) and the old controller paths at zero pending

## 5. Verification

- [ ] 5.1 `composer check:strict`, `npm run lint`, `openspec validate website-forms-submit-into-tickets --strict`
