# website-enquiry-intake Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: each website form submits into the destination it names (decisions 179 and 181).

## ADDED Requirements

### Requirement: A website form submits into the destination it names

Each published website form SHALL name its destination: a `ticket`, a `lead`, a `contact` or another pipelinq object (decision 181). There SHALL be no fixed default. A form is a `webForm` object in the pipelinq register: a `key` the website sends, a `status`, a `destination` (register and schema) with a `mapping` of fields onto that schema's properties, or several `writes` that are created all or none. The website endpoint SHALL submit into that destination through OpenRegister's submit service, and SHALL answer with its reference and `receivedAt`. The honeypot, rate limit, `source` allowlist and empty-message checks SHALL run before the submit. A form without a destination SHALL NOT be published. The write SHALL run as pipelinq's disabled system account, so a destination that grants create only to signed-in users is not opened to anonymous creates; the form's mapping is the list of what a visitor may set.

The three forms conduction-website ships (`website-support`, `website-partner`, `website-contact`) SHALL be seeded as published forms into `ticket`, with `ticketType: request`, `channel: website`, `status: new` and the form's key as `source`. An admin MAY point each one at another destination; a re-import does not overwrite that choice.

#### Scenario: The support form creates a ticket
- **GIVEN** the support form on the website, whose destination is `ticket`
- **WHEN** a visitor submits a message with an email address
- **THEN** a ticket with channel `website` exists before the response returns
- **AND** the response carries its reference
- **AND** no `enquiry` object is written

#### Scenario: The partner form creates a lead
- **GIVEN** a website form whose destination is `lead`
- **WHEN** a visitor submits it
- **THEN** a lead exists before the response returns and no ticket or enquiry is written

#### Scenario: A filled honeypot stores nothing
- **GIVEN** a submit with the honeypot field filled
- **WHEN** it arrives
- **THEN** no object exists and the answer carries no reference

#### Scenario: A refused payload names each property
- **GIVEN** a submit whose email address the destination's `format: email` refuses
- **WHEN** it arrives
- **THEN** the response is HTTP 422 with OpenRegister's findings, and nothing is stored

#### Scenario: A destination that is down answers try again later
- **GIVEN** the destination cannot be written
- **WHEN** a submit arrives
- **THEN** the response is HTTP 503 "try again later", nothing is stored, and no queue holds the submit

@e2e exclude an HTTP contract with no pipelinq screen: the only browser that posts to it is the conduction-website form. Asserted by PHPUnit (WebFormIntakeServiceTest, WebFormControllerTest); the live submit is owed in the live pass (decision 139)

### Requirement: Held website messages are drained into the destination their form names

`occ pipelinq:enquiry:drain` SHALL submit every open `enquiry` (status `new`) into the destination of the published website form whose key equals the enquiry's `source`, through the same intake a visitor's submit uses, and SHALL report each one as delivered (with the destination's reference), refused (with the finding codes) or unmapped (no published form for its source). An unmapped enquiry SHALL NOT be guessed into a destination. Without `--apply` the command SHALL write nothing. With `--apply` a delivered enquiry SHALL be deleted once its destination exists. The command SHALL exit 0 only when nothing is pending. `--include-intake-submissions` SHALL drain legacy `intakeSubmission` objects the same way, by their `source`.

#### Scenario: An enquiry from the support form becomes a ticket
- **GIVEN** an open enquiry with `source: website-support`
- **WHEN** `occ pipelinq:enquiry:drain --apply` runs
- **THEN** a ticket with channel `website` exists, the enquiry is deleted, and the report names the ticket's reference

#### Scenario: An enquiry whose source has no form is reported, not guessed
- **GIVEN** an open enquiry with `source: website-careers` and no published form with that key
- **WHEN** the drain runs
- **THEN** it is reported unmapped, stays held, and the command exits 1

#### Scenario: The default run changes nothing
- **WHEN** `occ pipelinq:enquiry:drain` runs without `--apply`
- **THEN** no object is written or deleted, and the report says where each open enquiry would go

@e2e exclude an occ command with no screen. Asserted by PHPUnit (HeldObjectDrainTest, EnquiryDrainCommandTest); the drain on a real instance is owed in the live pass (decision 139)

## MODIFIED Requirements

### Requirement: The enquiry schema is wired into the settings import

`webForm` SHALL appear in `SettingsLoadService::SCHEMA_SLUGS`, so the import writes its schema id to the `webForm_schema` app-config key. `enquiry` SHALL stay in the list until the drain reports zero pending, because the drain reads `enquiry_schema` to find what is still held.

#### Scenario: The schema ids reach app config
- **GIVEN** `SettingsLoadService::SCHEMA_SLUGS`
- **WHEN** it is read
- **THEN** it SHALL contain `webForm` and `enquiry`

#### Scenario: The schema id reaches app config
- **GIVEN** `SettingsLoadService::SCHEMA_SLUGS`
- **WHEN** it is read
- **THEN** it SHALL contain `webForm`, whose id the import writes to `webForm_schema`

@e2e exclude a missing slug fails silently at import time, not in the browser; asserted by PHPUnit via reflection (WebsiteEnquiryFragmentTest)

### Requirement: The intake endpoint accepts an anonymous submission

`POST /api/enquiry` (the address conduction-website posts to, its `source` being the form's key) and `POST /api/web-forms/{key}/submit` SHALL be reachable without authentication, without a CSRF token, and SHALL be rate-limited per anonymous caller. They SHALL accept `application/x-www-form-urlencoded` so a cross-origin browser POST is a CORS simple request and needs no preflight, and SHALL reflect the request Origin on the response so the submitting page can read the result. An `Idempotency-Key` header SHALL make a repeated submit answer the first one.

#### Scenario: An anonymous visitor can submit an enquiry
- **GIVEN** no authenticated session
- **WHEN** `POST /api/enquiry` is called with a `message` and `source: "website-support"`
- **THEN** the response SHALL be HTTP 201 with the destination's `id`, `reference` and `receivedAt`
- **AND** no `enquiry` object SHALL be created

#### Scenario: The submitted contact details are readable afterwards
- **GIVEN** a support form submit with `contactName`, `contactEmail` and `organisation`
- **WHEN** the created ticket is read back
- **THEN** `reporterName`, `reporterEmail` and `reporterOrganisation` SHALL hold those three values, unchanged

@e2e exclude the endpoint is an HTTP contract with no pipelinq screen. Asserted by PHPUnit (WebFormControllerTest)

### Requirement: The endpoint decides state, the submitter does not

Only the fields the form maps SHALL reach the destination object, and the form's fixed values (for example `channel`, `status`, `ticketType`) SHALL be set whatever the submitter sent. A field sent as a nested shape SHALL be treated as absent; a list of plain values (a multiple-choice answer) SHALL be kept. A blank field the form lists in `fieldFallbacks` SHALL be filled from the first filled field it names.

#### Scenario: A submitted status is discarded
- **GIVEN** a submit carrying `status: "converted"` on a form that does not map `status`
- **WHEN** it is accepted
- **THEN** the created object's `status` SHALL be the form's fixed value

#### Scenario: A submitted conversion link is discarded
- **GIVEN** a submit carrying `lead` set to an existing lead's uuid, on a form that does not map `lead`
- **WHEN** it is accepted
- **THEN** the created object SHALL have no `lead` set

#### Scenario: A submitted handler is discarded
- **GIVEN** a submit carrying `assignee: "admin"`, on a form that does not map `assignee`
- **WHEN** it is accepted
- **THEN** the created object SHALL have no `assignee` set

#### Scenario: A field sent as an array is treated as absent
- **GIVEN** a submit carrying `contactPhone` as a nested shape
- **WHEN** it is accepted
- **THEN** the created object SHALL have no phone set, and the request SHALL NOT error

#### Scenario: Framework route parameters are not stored
- **GIVEN** a submit whose request params include Nextcloud's own `_route`
- **WHEN** it is accepted
- **THEN** the created object SHALL have no `_route`, because no form maps it

#### Scenario: A blank title is filled from the organisation
- **GIVEN** a submit without a title, with `organisation: "Acme BV"`, on a form whose `fieldFallbacks.title` lists `organisation`
- **WHEN** it is accepted
- **THEN** the submitted title SHALL be `Acme BV`

@e2e exclude a discarded field is not observable in any screen. Asserted by PHPUnit (WebFormIntakeServiceTest)

### Requirement: The source is refused unless it is on the allowlist

The endpoint SHALL refuse a submit whose key names no published form, with HTTP 400 and nothing stored. A draft or unpublished form SHALL NOT take submits. Adding a form is an admin publishing a `webForm`, checked against its destination, not a code change.

#### Scenario: An unknown source is refused
- **GIVEN** a submit with `source: "not-a-real-form"`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and nothing SHALL be stored

#### Scenario: A known source is accepted
- **GIVEN** a submit with `source: "website-partner"`, a published form
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 201

@e2e exclude a real form always sends a known key. Asserted by PHPUnit (WebFormIntakeServiceTest)

### Requirement: An empty submission is refused

The endpoint SHALL refuse a submit that fills none of the fields its form lists in `requireAnyOf` (the seeded website forms: `message` or `contactEmail`), or, without that list, none of its mapped fields, with HTTP 400 and nothing stored. An answer longer than 20,000 characters SHALL be refused whole, never cut.

#### Scenario: A submission with nothing to act on is refused
- **GIVEN** a submit with only `contactName` and `source`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and nothing SHALL be stored

@e2e exclude the browser form marks its fields required, so this refusal is only reachable by a direct POST. Asserted by PHPUnit (WebFormIntakeServiceTest)

### Requirement: A filled honeypot is refused

The endpoint SHALL accept optional fields a human never fills (`website`, and OpenRegister's `_hp`). When one arrives non-empty, or as a list, the submit SHALL be refused with HTTP 400 and nothing stored.

#### Scenario: A bot that fills every field is refused
- **GIVEN** a submit with the honeypot field set to any non-empty value
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and nothing SHALL be stored

#### Scenario: A honeypot sent as an array is refused, not read as empty
- **GIVEN** a submit with the honeypot field sent as `["x"]`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and nothing SHALL be stored

@e2e exclude the honeypot is hidden from humans by design. Asserted by PHPUnit (WebFormIntakeServiceTest)

## REMOVED Requirements

### Requirement: The enquiry schema holds intake data, not deal data

**Reason**: a schema that holds a message until a person converts it is the in-between layer decision 179 forbids.
**Migration**: `occ pipelinq:enquiry:drain` submits each open enquiry into the destination of the website form for its `source`, or reports it. Until it reports zero pending the schema stays, closed to creates and marked `staging` so no form can name it; then it is removed.

### Requirement: A converted enquiry becomes a client, a contact and a lead

**Reason**: each website form now creates its own destination. Making a lead from a ticket is the existing ticket-to-lead action a person takes.
**Migration**: the "Enquiry to lead" flow is removed from the register configuration. An instance that imported it keeps the flow object, which shipped disabled.
