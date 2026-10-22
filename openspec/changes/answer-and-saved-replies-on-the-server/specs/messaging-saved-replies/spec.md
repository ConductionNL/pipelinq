# messaging-saved-replies: replies and message clients on the server

## ADDED Requirements

### Requirement: The server offers saved replies filled for the record

`GET /api/saved-replies` SHALL answer the active saved replies whose `channels` hold `channel`, the party's correspondence language first and then by title, as `{ uuid, name, values: { body } }`, with `{{ticket.title}}`, `{{client.name}}`, `{{contact.name}}` and `{{agent.name}}` filled from the ticket, contact and client given (a ticket names its contact and client) and the signed-in user. A placeholder without a value SHALL stay as written. Records SHALL be read as the signed-in user.

@e2e tests/e2e/messaging-saved-replies.spec.ts picks a saved reply on SMS and on a portal answer.

#### Scenario: A Dutch contact
- **GIVEN** an English and a Dutch portal reply and a contact whose language is nl
- **THEN** the Dutch reply comes first, with the contact's name filled in

### Requirement: A message names the client of its contact

Before a WhatsApp or SMS message is stored, the server SHALL set its `clientId` to the `client` of its `contactId` when it has none or the contact changed. A repair step SHALL do the same for stored messages.

@e2e exclude a pre-save rule; asserted by PHPUnit in tests/Unit/Repair/BackfillConversationFieldsTest.php

#### Scenario: Messages on a client page
- **GIVEN** a message to a contact of client Acme
- **THEN** it carries Acme's id in `clientId`, and Acme's page lists it
