# Tasks: website-forms-submit-into-tickets

## 1. Website form into its destination

- [x] 1.1 Each website form names its destination (ticket, lead, contact, ...); the endpoint submits into it through `FormSubmitService`; honeypot, rate limit, allowlist and empty check first; a form without a destination is not published
  - Spec ref: specs/website-enquiry-intake/spec.md
  - Files: lib/Settings/register.d/26-web-form.json (webForm schema, three seed forms, ticket reporter fields), lib/Service/WebForm/WebFormIntakeService.php (replaces EnquiryIntakeService), lib/Service/WebForm/WebFormFinder.php, lib/Controller/WebFormController.php (replaces EnquiryController), appinfo/routes.php
  - Test: tests/Unit/Service/WebForm/WebFormIntakeServiceTest.php (ticket form, lead form, no enquiry, honeypot with its control, refusals, 422 findings, 503), WebFormFinderTest, tests/Unit/Controller/WebFormControllerTest.php
- [x] 1.2 Destination reference and `receivedAt` in the answer; same for `PortalRequestService`
  - Files: lib/Controller/WebFormController.php, lib/Service/Portal/PortalRequestService.php; Test: PortalRequestServiceTest::testSubmitCreatesRequestTicket
- [ ] 1.3 Live: submit each seeded website form on an instance and read the ticket back (live pass, decision 139)

## 2. Landing page into lead

- [x] 2.1 `LandingPageProvisioningService` declares the form with destination `lead`: `LandingPageRequestedEvent` carries `form.destination` (portaliq validates it on provisioning, portaliq#1497)
  - Files: lib/Service/Marketing/CampaignFormDestination.php, lib/Service/LandingPageProvisioningService.php (`formFor`), lib/Settings/register.d/98-lead-submitted-by-form.json (submitter and submission properties on `lead`; `pipeline` and `client` marked server-set)
  - Test: tests/Unit/Service/Marketing/CampaignFormDestinationTest.php (against the composed lead schema as it ships), LandingPageProvisioningServiceTest::testTheRequestNamesTheLeadASubmitCreates
- [x] 2.2 Contact and client matching and the touchpoint run on the lead's own create events, keyed on the nonce
  - Files: lib/Service/Marketing/SubmittedLeadIntake.php, lib/Listener/SubmittedLeadListener.php (registered for ObjectCreatingEvent and ObjectCreatedEvent)
  - Test: tests/Unit/Service/Marketing/SubmittedLeadIntakeTest.php, tests/Unit/Listener/SubmittedLeadListenerTest.php
- [ ] 2.3 Remove `LandingPageFormSubmittedEvent` and its listener once portaliq stops relaying (after portaliq#1497 lands and its landing drain reports zero)
- [x] 2.4 Existing forms get their destination once, by a repair step
  - Files: lib/Service/Marketing/FormDestinationBackfill.php, lib/Repair/NameFormDestinations.php, appinfo/info.xml (post-migration, version bump)
  - Test: tests/Unit/Service/Marketing/FormDestinationBackfillTest.php, tests/Unit/Repair/NameFormDestinationsTest.php
- [ ] 2.5 Live: create a campaign landing page, submit its form, read back the lead with its contact and `submit` touchpoint; run `occ maintenance:repair` on an instance with an older form and submit that form (live pass, decision 139)

## 3. public-intake-forms

- [x] 3.1 Apply the delta: one model, forms built elsewhere with a pipelinq destination; drop the six generic builder requirements (L503 to L585) and the `satisfactionSurvey`/`surveyResponse` retirement contradiction with `customer-satisfaction`, noting survey responses are their own destination
- [x] 3.2 `omnichannel-registratie` "Unified Inbox": add a note that messages without a form are out of decision 179 per Q5
- [x] 3.3 Publishing a form is checked against its destination by OpenRegister's `FormDestinationValidator`, refused on any finding (Q9), on every save path
  - Files: lib/Service/WebForm/WebFormBindingCheck.php, lib/Listener/WebFormBindingListener.php (creating and updating events)
  - Test: WebFormBindingCheckTest, tests/Unit/Listener/WebFormBindingListenerTest.php
- [x] 3.4 Nextcloud Forms through a checked binding (Q6): the binding is judged with the questions' real types; a Forms submit creates the destination in the submit event
  - Files: lib/Service/WebForm/FormsQuestionReader.php, lib/Listener/FormsSubmissionListener.php
  - Test: FormsQuestionReaderTest, tests/Unit/Listener/FormsSubmissionListenerTest.php
- [ ] 3.5 Admin screen for website forms: list, destination, field mapping, publish with findings (UI waits for board PqIntakeFormulier, design-system #194, decision 162)
- [ ] 3.6 Live: bind a Nextcloud Forms form, submit it, read the ticket back (live pass, decision 139)

## 4. Drain and remove

- [x] 4.1 `occ pipelinq:enquiry:drain` (and `--include-intake-submissions`) into the destination of the form for each `source`; report delivered, refused and unmapped
  - Files: lib/Service/WebForm/HeldObjectDrain.php, lib/Command/EnquiryDrainCommand.php, appinfo/register_command.php
  - Test: HeldObjectDrainTest, tests/Unit/Command/EnquiryDrainCommandTest.php
- [x] 4.2a Close `enquiry` to new writes and mark it `staging`; remove the "Enquiry to lead" flow (27-default-sales-pipeline.json keeps the pipeline seed) and the old controller and service
- [ ] 4.2b Run the drain on each instance and remove `enquiry` (26-website-enquiry.json and its `SCHEMA_SLUGS` entry) at zero pending (live pass, decision 139)

## 5. Verification

- [x] 5.1 `composer check:strict`, `npm run lint`, `openspec validate website-forms-submit-into-tickets --strict` (check:strict exit 0 at 925ceb31c; npm run lint not run locally: no JS or Vue file changed, CI runs it)
