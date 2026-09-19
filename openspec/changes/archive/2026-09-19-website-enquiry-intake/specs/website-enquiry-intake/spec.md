## Purpose

Gives an anonymous website visitor a way to reach the CRM that cannot be used to write CRM state. An enquiry records what someone typed and nothing more; turning it into a client, a contact and a lead is a decision a person in the sales group makes afterwards.

## ADDED Requirements

### Requirement: The enquiry schema holds intake data, not deal data

The `enquiry` schema SHALL declare `title`, `contactName`, `contactEmail`, `contactPhone`, `organisation`, `message`, `source`, `pageUrl`, `locale`, `receivedAt`, `status`, `client`, `contact`, `lead`, `handledBy` and `handledAt`. Only `title` SHALL be required. `status` SHALL be constrained to `new`, `converted`, `spam` and `closed`, and default to `new`. The schema SHALL declare `authorization.create` as `["public", "authenticated"]` and read, update and delete as `["authenticated"]`.

#### Scenario: An enquiry needs no client and no pipeline

- **GIVEN** the `enquiry` schema
- **WHEN** its `required` list is read
- **THEN** it SHALL contain `title` and SHALL NOT contain `client` or `pipeline`

#### Scenario: The four fields the website posts exist on the schema

- **GIVEN** the `enquiry` schema
- **WHEN** its properties are read
- **THEN** `contactName`, `contactEmail`, `contactPhone` and `organisation` SHALL each be declared

@e2e exclude schema shape is asserted by PHPUnit against the register fragment file (WebsiteEnquiryFragmentTest); it has no UI surface of its own

### Requirement: The enquiry schema is wired into the settings import

`enquiry` SHALL appear in `SettingsLoadService::SCHEMA_SLUGS`, so the import writes its schema id to the `enquiry_schema` app-config key.

#### Scenario: The schema id reaches app config

- **GIVEN** `SettingsLoadService::SCHEMA_SLUGS`
- **WHEN** it is read
- **THEN** it SHALL contain `enquiry`

@e2e exclude a missing slug fails silently at import time, not in the browser; asserted by PHPUnit via reflection (WebsiteEnquiryFragmentTest)

### Requirement: The intake endpoint accepts an anonymous submission

`POST /api/enquiry` SHALL be reachable without authentication, without a CSRF token, and SHALL be rate-limited per anonymous caller. It SHALL accept `application/x-www-form-urlencoded` so a cross-origin browser POST is a CORS simple request and needs no preflight, and SHALL reflect the request Origin on the response so the submitting page can read the result.

#### Scenario: An anonymous visitor can submit an enquiry

- **GIVEN** no authenticated session
- **WHEN** `POST /api/enquiry` is called with `title` and `source: "website-support"`
- **THEN** the response SHALL be HTTP 201 and an `enquiry` object SHALL exist with that title

#### Scenario: The submitted contact details are readable afterwards

- **GIVEN** an enquiry submitted with `contactName`, `contactEmail` and `organisation`
- **WHEN** the stored object is read back
- **THEN** each of those three values SHALL be present, unchanged

@e2e exclude the endpoint is an HTTP contract with no Pipelinq UI surface: the only browser that posts to it is the conduction-website support form, whose Playwright suite lives in the conduction-website repo and is invisible to this gate. Asserted here by PHPUnit (EnquiryControllerTest)

### Requirement: The endpoint decides state, the submitter does not

The intake endpoint SHALL accept only `title`, `contactName`, `contactEmail`, `contactPhone`, `organisation`, `message`, `source`, `pageUrl` and `locale` from the caller. It SHALL set `status` to `new` and `receivedAt` to the server clock on every submission, and SHALL discard any `status`, `client`, `contact`, `lead`, `handledBy` or `handledAt` the caller supplied.

#### Scenario: A submitted status is discarded

- **GIVEN** a submission carrying `status: "converted"`
- **WHEN** it is accepted
- **THEN** the stored object's `status` SHALL be `new`

#### Scenario: A submitted conversion link is discarded

- **GIVEN** a submission carrying `lead` set to an existing lead's uuid
- **WHEN** it is accepted
- **THEN** the stored object SHALL have no `lead` set

#### Scenario: A submitted handler is discarded

- **GIVEN** a submission carrying `handledBy: "admin"`
- **WHEN** it is accepted
- **THEN** the stored object SHALL have no `handledBy` set

#### Scenario: A field sent as an array is treated as absent

- **GIVEN** a submission carrying `title` as `["a", "b"]`
- **WHEN** it is accepted
- **THEN** the stored object's `title` SHALL come from the fallback, and the request SHALL NOT error

#### Scenario: Framework route parameters are not stored

- **GIVEN** a submission whose request params include Nextcloud's own `_route`
- **WHEN** it is accepted
- **THEN** the stored object SHALL have no `_route`

@e2e exclude a discarded field is not observable in any Pipelinq screen, only in the stored object. Asserted by PHPUnit (EnquiryIntakeServiceTest), which is also the only place a hostile payload can be sent deliberately

### Requirement: The source is refused unless it is on the allowlist

The endpoint SHALL refuse a submission whose `source` is not one it recognises, with HTTP 400 and no object written. A submission with no `source` SHALL be refused the same way.

#### Scenario: An unknown source is refused

- **GIVEN** a submission with `source: "not-a-real-form"`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and no `enquiry` object SHALL be created

#### Scenario: A known source is accepted

- **GIVEN** a submission with `source: "website-partner"`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 201

@e2e exclude a refusal on an unknown source cannot be produced from a real form, which always sends a known one. Asserted by PHPUnit (EnquiryIntakeServiceTest)

### Requirement: An empty submission is refused

The endpoint SHALL refuse a submission that carries neither a `message` nor a `contactEmail`, with HTTP 400 and no object written. A row with a title and no way to answer it is not an enquiry.

#### Scenario: A submission with nothing to act on is refused

- **GIVEN** a submission with only `title` and `source`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and no `enquiry` object SHALL be created

@e2e exclude the browser form marks its fields required, so this refusal is only reachable by a direct POST. Asserted by PHPUnit (EnquiryIntakeServiceTest)

### Requirement: A filled honeypot is refused

The endpoint SHALL accept an optional field that a human never fills. When it arrives non-empty the submission SHALL be refused with HTTP 400 and no object written.

#### Scenario: A bot that fills every field is refused

- **GIVEN** a submission with the honeypot field set to any non-empty value
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and no `enquiry` object SHALL be created

#### Scenario: A honeypot sent as an array is refused, not read as empty

- **GIVEN** a submission with the honeypot field sent as `["x"]`
- **WHEN** it is posted
- **THEN** the response SHALL be HTTP 400 and no `enquiry` object SHALL be created

@e2e exclude the honeypot is hidden from humans by design, so a browser driver filling it would not reproduce a real bot. Asserted by PHPUnit (EnquiryIntakeServiceTest)

### Requirement: A converted enquiry becomes a client, a contact and a lead

Marking an enquiry `converted` SHALL create the client, the contact and the lead it implies, and SHALL write all three back onto the enquiry. The conversion SHALL be a declared OpenRegister flow rather than a service method, so the step that performs it has a call site that is visible and switchable rather than a button somebody has to remember to wire up.

The flow SHALL ship as an `x-openregister-flows` declaration on the `enquiry` schema, which materialises it into the flow table `enabled = false` and ownerless. Adopting and enabling it SHALL remain two separate decisions a person makes.

#### Scenario: One status change produces three objects

- **GIVEN** an enquiry carrying an organisation, a contact name and an email
- **WHEN** its `status` is set to `converted`
- **THEN** a `client`, a `contact` and a `lead` SHALL be created, and the enquiry SHALL carry all three uuids

#### Scenario: An unconverted enquiry produces nothing

- **GIVEN** an enquiry whose status is not `converted`
- **WHEN** it is updated
- **THEN** no client, contact or lead SHALL be created

#### Scenario: Converting twice does not convert twice

- **GIVEN** an enquiry that already carries a `lead`
- **WHEN** it is updated again
- **THEN** the flow SHALL do nothing

@e2e exclude the conversion runs in OpenRegister's flow engine on a background worker, so no browser drives it. Verified against a live instance instead: run 68 completed, counts moved 28 to 29 client, 6 to 7 contact, 22 to 23 lead, and the enquiry carried all three uuids

### Requirement: The addressbook identity is resolved, never minted

`client` and `contact` both require `contactsUid`, whose own declaration says it is resolved or created via ContactVcardService and never minted locally. The flow SHALL obtain that identity through a pipelinq node that calls `ContactVcardService`, and SHALL NOT write a value of its own devising.

The node SHALL refuse, rather than continue, when an identity cannot be provisioned. It SHALL pass only the fields its configuration names, so provisioning an organisation does not reach for a person's email address.

#### Scenario: A client and a contact from one enquiry get different identities

- **GIVEN** an enquiry with both an organisation and a person's email
- **WHEN** it is converted
- **THEN** the client's `contactsUid` and the contact's `contactsUid` SHALL differ

#### Scenario: An unavailable addressbook refuses rather than inventing a uid

- **GIVEN** a Contacts backend that cannot provision
- **WHEN** the node runs
- **THEN** it SHALL throw, and no object SHALL be written with a locally minted uid

@e2e exclude both are properties of a PHP flow node with no UI surface; asserted by PHPUnit (ProvisionContactIdentityNodeTest), including the conflation regression found on a live rig where both uids came back identical
