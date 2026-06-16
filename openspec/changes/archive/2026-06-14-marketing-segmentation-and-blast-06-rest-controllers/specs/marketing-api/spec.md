# Marketing Segmentation and Blast — REST Controllers

## ADDED Requirements

### Requirement: API Endpoints CRUD and Query

All Blast, Segment, CampaignTemplate API endpoints SHALL support standard
CRUD operations with proper authorization and error handling. User identity
SHALL be derived from `IUserSession`, never trusted from the frontend.

#### Scenario: GET /api/blasts returns paginated list with filters

- **GIVEN** a current user with Pipelinq access
- **WHEN** they GET `/api/blasts?status=sent&page=1&limit=20`
- **THEN** the response SHALL be HTTP 200 with a `data[]` array and a `pagination` object
- **AND** filtering by status SHALL return only matching blasts

#### Scenario: POST /api/blasts creates new blast in draft

- **GIVEN** a valid Segment, Template, and connector source
- **WHEN** POST `/api/blasts` with name/segmentId/templateId/channel/connectorSourceId
- **THEN** the response SHALL be HTTP 201 with a new Blast object in draft status

#### Scenario: Error responses use generic messages

- **GIVEN** an invalid segmentId in the POST body
- **WHEN** POST `/api/blasts`
- **THEN** the response SHALL be HTTP 400 with a generic message ("Invalid segment") that does NOT expose internal details

#### Scenario: User identity from IUserSession only

- **GIVEN** a POST that includes `"createdBy": "admin-user-id"` in the body
- **WHEN** the controller processes the request
- **THEN** it SHALL ignore the body value and set createdBy from `IUserSession.getUser().getUID()`

#### Scenario: Segment create validates rule tree

- **GIVEN** a POST `/api/segments` with a rule tree
- **WHEN** the controller processes it
- **THEN** it SHALL call `SegmentService.validateRules()` before save and reject invalid trees with field-level errors

#### Scenario: Template create validates compliance

- **GIVEN** a POST `/api/templates` for an email channel
- **WHEN** the controller processes it
- **THEN** it SHALL call `ComplianceService.validateTemplate()` and reject templates missing the unsubscribe token or physical address
