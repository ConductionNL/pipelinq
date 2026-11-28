# unify-ticket-supertype Delta: website-submissions-link-to-client

## ADDED Requirements

### Requirement: The website submissions are a lens over form tickets

pipelinq SHALL offer a page listing the tickets whose `source` names a form, newest first, with the lenses Not linked yet
(no client, the default), Linked (a client), Closed and All.

#### Scenario: A new catering request

- **GIVEN** the contact form created a ticket from Joke Smit of Bakkerij De Graaf, without a client
- **WHEN** a colleague opens Website submissions
- **THEN** the ticket is listed under Not linked yet with Joke Smit, Bakkerij De Graaf, website-contact and /contact

### Requirement: A colleague links a submission to a client and a contact in one step

On a ticket without a client, Link to a client SHALL show the submission as sent and SHALL let the colleague choose, for
the client, a new client named after the typed organisation or an existing client, and for the contact person, a new
contact from the typed name and e-mail or an existing contact, suggesting an existing client with the typed organisation
name and an existing contact with the typed e-mail. Confirming SHALL create what is new, link the ticket's `client` and
`contact`, and, when asked, create a lead for that client and contact in the default pipeline. Every write SHALL run as
the signed-in colleague.

#### Scenario: A new client, a known contact

- **GIVEN** no client named Bakkerij De Graaf and a contact with joke@bakkerijdegraaf.nl
- **WHEN** the colleague keeps "New client Bakkerij De Graaf", picks the suggested contact and asks for a lead
- **THEN** the client is created, the ticket points at it and at the existing contact, and a lead "Catering voor de opening van onze nieuwe winkel" exists for both
