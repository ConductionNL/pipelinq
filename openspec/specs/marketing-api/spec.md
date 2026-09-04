---
status: in-progress
---

# marketing-api Specification

**OpenSpec changes**: [marketing-segments-ui-repair](../../changes/marketing-segments-ui-repair/) _(in progress)_ — fixes pipelinq#773 (`SegmentService::resolveSchemaProperties()` calling a removed `SchemaMapper::find()` parameter), removes the `@e2e exclude` this blocked, and adds `PATCH /api/segments/{id}` and `POST /api/segments/preview`.

## Purpose
Exposes the REST API for marketing blasts, segments, and campaign templates with standard CRUD, pagination, and filtering. It derives user identity from the server session rather than trusting the request body, validates segment rule trees and template compliance before saving, and returns generic error messages that do not leak internal details.
## Requirements
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

`SegmentService::resolveSchemaProperties()` no longer passes the `published` named argument OpenRegister's `SchemaMapper::find()` removed (commit `ea99a5004`), so the schema lookup this scenario depends on no longer throws (marketing-segments-ui-repair, pipelinq#773). Exercised end to end by `tests/e2e/spec-coverage/marketing.spec.ts` ("POST /api/segments validates the rule tree before saving") against the app's real `contact` schema fields.

- **GIVEN** a POST `/api/segments` with a rule tree
- **WHEN** the controller processes it
- **THEN** it SHALL call `SegmentService.validateRules()` before save and reject invalid trees with field-level errors

#### Scenario: Template create validates compliance

- **GIVEN** a POST `/api/templates` for an email channel
- **WHEN** the controller processes it
- **THEN** it SHALL call `ComplianceService.validateTemplate()` and reject templates missing the unsubscribe token or physical address

### Requirement: Segment Update and Unsaved-Tree Preview

`SegmentController` SHALL expose `PATCH /api/segments/{id}` to update an
existing Segment's name, description, audience or rule tree, re-validating
the rule tree with `SegmentService.validateRules()` before persisting exactly
as `POST /api/segments` does. It SHALL also expose `POST /api/segments/preview`
to validate and estimate a rule tree that has not been persisted yet — the
call SegmentBuilder's live-validation and debounced size-estimate need before
a Segment has an id to call `POST /api/segments/{id}/size` against.

#### Scenario: PATCH updates a Segment after re-validation

- **GIVEN** an existing Segment and an edited rule tree
- **WHEN** PATCH `/api/segments/{id}` is called with the edited tree
- **THEN** the response SHALL be HTTP 200 with the updated Segment and a freshly recomputed `estimatedSize`
- **AND** an invalid edited tree SHALL be rejected with HTTP 400 and the existing Segment SHALL remain unchanged

#### Scenario: PATCH on an unknown Segment id is a generic 404

- **GIVEN** a Segment id that does not exist
- **WHEN** PATCH `/api/segments/{id}` is called
- **THEN** the response SHALL be HTTP 404 with a generic message

#### Scenario: POST /api/segments/preview validates and estimates an unsaved tree

- **GIVEN** an entityType and a rule tree that has not been saved
- **WHEN** POST `/api/segments/preview` is called
- **THEN** the response SHALL be HTTP 200 with `{valid, error, estimatedSize}`, counting matches only when `valid` is true
- **AND** no Segment object SHALL be persisted by this call

