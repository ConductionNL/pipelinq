# website-enquiry-intake Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: each website form submits into the destination it names (decisions 179 and 181).

## ADDED Requirements

### Requirement: A website form submits into the destination it names

Each published website form SHALL name its destination: a `ticket`, a `lead`, a `contact` or another pipelinq object (decision 181). There SHALL be no fixed default. The website endpoint SHALL submit into that destination through OpenRegister's submit service, and SHALL answer with its reference and `receivedAt`. The honeypot, rate limit, `source` allowlist and empty-message checks SHALL run before the submit. A form without a destination SHALL NOT be published.

#### Scenario: The support form creates a ticket
- **GIVEN** the support form on the website, whose destination is `ticket`
- **WHEN** a visitor submits a message with an email address
- **THEN** a ticket with channel `website` exists before the response returns
- **AND** the response carries its reference
- **AND** no `enquiry` object is written

#### Scenario: The partner form creates a lead
- **GIVEN** the partner form on the website, whose destination is `lead`
- **WHEN** a visitor submits it
- **THEN** a lead exists before the response returns and no ticket or enquiry is written

#### Scenario: A filled honeypot stores nothing
- **GIVEN** a submit with the honeypot field filled
- **WHEN** it arrives
- **THEN** no object exists and the answer carries no reference

## REMOVED Requirements

### Requirement: The enquiry schema holds intake data, not deal data

**Reason**: a schema that holds a message until a person converts it is the in-between layer decision 179 forbids.
**Migration**: `occ pipelinq:enquiry:drain` submits each open enquiry into the destination of the website form for its `source`, or reports it; the schema is removed at zero pending.

### Requirement: A converted enquiry becomes a client, a contact and a lead

**Reason**: each website form now creates its own destination. Making a lead from a ticket is the existing ticket-to-lead action a person takes.
**Migration**: the "Enquiry to lead" flow is removed with the `enquiry` schema.
