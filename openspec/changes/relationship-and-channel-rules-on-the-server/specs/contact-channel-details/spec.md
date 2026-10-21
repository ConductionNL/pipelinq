# Contact channel details: the server keeps the single fields

## ADDED Requirements

### Requirement: The server keeps the single email and phone fields

When a client or contact is saved with a changed `emails[]` or `phones[]` list, the server MUST set the single `email` or `phone` field to the value of the entry marked primary, or of the first entry when none is marked, or to an empty string when the list is empty. This MUST happen before the object is stored, for every writer. A save that does not change the list MUST leave the single field as the writer sent it.

@e2e exclude backend listener: the fields are set before the object is stored; asserted by PHPUnit in tests/Unit/Listener/ContactChannelMirrorListenerTest.php

#### Scenario: A new primary email becomes the email
- **GIVEN** a contact whose primary email is old@example.org
- **WHEN** it is saved with new@example.org marked primary
- **THEN** its `email` MUST be new@example.org

#### Scenario: An emptied list clears the field
- **WHEN** a contact is saved with an empty `emails` list
- **THEN** its `email` MUST be empty

#### Scenario: A writer that sets only the email is left alone
- **WHEN** a contact is saved with a new `email` and an unchanged `emails` list
- **THEN** its `email` MUST be the value the writer sent
