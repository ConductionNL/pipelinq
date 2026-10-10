# website-enquiry-intake Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: the website form writes a ticket directly (decision 179).

## ADDED Requirements

### Requirement: A website enquiry is a ticket from the first request

The website form SHALL submit into `ticket` through OpenRegister's submit service, with `ticketType: request`, channel `website` and `source` from the allowlist. The response SHALL carry the ticket's reference and `receivedAt`. The honeypot, rate limit, allowlist and empty-message checks SHALL run before the submit.

#### Scenario: A visitor's message is a ticket at once
- **GIVEN** the support form on the website
- **WHEN** a visitor submits a message with an email address
- **THEN** a ticket with channel `website` exists before the response returns
- **AND** the response carries its reference
- **AND** no `enquiry` object is written

#### Scenario: A filled honeypot stores nothing
- **GIVEN** a submit with the honeypot field filled
- **WHEN** it arrives
- **THEN** no ticket exists and the answer carries no reference

## REMOVED Requirements

### Requirement: The enquiry schema holds intake data, not deal data

**Reason**: a schema that holds a message until a person converts it is the in-between layer decision 179 forbids.
**Migration**: `occ pipelinq:enquiry:drain` turns each open enquiry into a ticket; the schema is removed at zero pending.

### Requirement: A converted enquiry becomes a client, a contact and a lead

**Reason**: the website form now creates a ticket. Making a lead from a ticket is the existing ticket-to-lead action a person takes.
**Migration**: the "Enquiry to lead" flow is removed with the `enquiry` schema.
