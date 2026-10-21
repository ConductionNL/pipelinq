# Contact relationship mapping: the server keeps the inverse

## ADDED Requirements

### Requirement: The server keeps the inverse relationship

Every relationship MUST have its inverse row, whoever writes it: the detail page, the API, an import or a sync. The server MUST create the inverse (parties swapped, `type` and `inverseType` swapped, `fromType` and `toType` swapped, `category`, `notes`, `startDate`, `endDate` and `strength` copied) after a relationship is created, MUST bring the inverse in line after a relationship is edited, and MUST delete the inverse after a relationship is deleted or moved to the trash. An edit MUST NOT change any field of the inverse other than the ones that differ, and the work MUST stop once both rows agree.

@e2e exclude backend listener: the pair is written by the server; asserted by PHPUnit in tests/Unit/Listener/RelationshipInverseListenerTest.php

#### Scenario: Creating a relationship creates its inverse once
- **GIVEN** contacts Jan and Sophie
- **WHEN** a relationship from Jan to Sophie with type "ouder" and inverseType "kind" is created through any writer
- **THEN** a relationship from Sophie to Jan with type "kind" and inverseType "ouder" MUST exist
- **AND** no further row MUST be written

#### Scenario: Editing the notes keeps both parties
- **GIVEN** the pair above
- **WHEN** the notes of Jan's row are edited
- **THEN** Sophie's row MUST carry the same notes
- **AND** both rows MUST still name Jan and Sophie as their parties

#### Scenario: Changing the type moves the inverse
- **WHEN** Jan's row changes from "ouder" to "partner"
- **THEN** Sophie's row MUST change to "partner" and no new row MUST be added

#### Scenario: Deleting a relationship deletes its inverse
- **WHEN** Jan's row is deleted or moved to the trash
- **THEN** Sophie's row MUST be deleted too
