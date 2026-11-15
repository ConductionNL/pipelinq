# Delta: notifications-activity

## ADDED Requirements

### Requirement: Each object type publishes its own created activity
Pipelinq MUST publish a created activity only for the object types that have one: a lead publishes `lead_created` and a request publishes `request_created`, each carrying the object's own name. Any other object type, a contact among them, MUST NOT publish a pipelinq created activity. A contact's name MUST be read from its `name` field.

#### Scenario: A contact is created
@e2e exclude The activity is rendered by Nextcloud's Activity app, not a pipelinq page. Asserted in tests/Unit/Service/ObjectEventHandlerServiceTest.php (testCreatedActivityThroughRealServices, through the real handler, dispatcher and ActivityService) and tests/Unit/Service/ActivityServiceTest.php (testPublishCreatedForContactPublishesNothing).
- **GIVEN** a user adds a contact person to a client
- **WHEN** the contact is saved
- **THEN** pipelinq MUST NOT publish a "Lead created" activity
- **AND** the only created activity for it MUST be the one OpenRegister writes, which names the contact

#### Scenario: A lead is created
@e2e exclude Asserted in tests/Unit/Service/ObjectEventHandlerServiceTest.php (testCreatedActivityThroughRealServices).
- **GIVEN** a user creates a lead titled "Tender"
- **WHEN** the lead is saved
- **THEN** pipelinq MUST publish `lead_created` with the title "Tender"
