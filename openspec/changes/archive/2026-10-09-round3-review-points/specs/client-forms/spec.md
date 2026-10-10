## ADDED Requirements

### Requirement: A client's name, email and phone can be saved from the client page (REQ-R3-001)

The client and contact schemas SHALL NOT mark `name`, `email` or `phone` as
read-only, so OpenRegister accepts a change made in the Edit dialog. The change
SHALL still be written back to the linked Nextcloud Contact.

#### Scenario: Change a client's phone

@e2e exclude The schema half is asserted on the merged register in tests/Unit/Settings/ReviewRound3RegisterTest.php; tests/e2e/workflows/client-crud.spec.ts renames a client through the object API.
- GIVEN a client without a phone number
- WHEN the user enters a phone number in the client Edit dialog and saves
- THEN the client SHALL carry the phone number
- AND OpenRegister SHALL NOT answer "Cannot modify readOnly property"
- AND pipelinq SHALL write the change back to the linked Nextcloud Contact
