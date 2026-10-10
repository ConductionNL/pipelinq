# public-intake-forms Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: resolves the contradiction between the in-app builder (L24 to L499) and the Forms leaf (L587 to L651) under decision 179.

## ADDED Requirements

### Requirement: A public pipelinq form is a fleet form with a pipelinq destination

A public form that feeds pipelinq SHALL be authored in buildiq, hosted by portaliq, and declare a pipelinq destination (`ticket`, `lead`, or a contact plus lead). It SHALL pass OpenRegister's form destination validator before it is published. A submit SHALL create the destination in the request. pipelinq SHALL ship no form builder and no submission store of its own.

#### Scenario: A form into a lead without a title is refused
- **GIVEN** a form into `lead` that maps no field and no fixed value to the required `title`
- **WHEN** its author publishes it
- **THEN** the publish is refused with `required-unmapped` on `title`

## REMOVED Requirements

### Requirement: Form Builder

**Reason**: authoring belongs to buildiq (ADR-085); a second builder would need its own destination check.
**Migration**: none; the builder was never built.

### Requirement: Form Data Storage

**Reason**: the `formSubmission` log schema is an in-between store (decision 179).
**Migration**: none; never built. Submission history reads destination objects by their `formId` provenance.

### Requirement: Form Success and Error Handling

**Reason**: its retry queue for server errors is an in-between store. Errors follow hydra `form-destination`: 422 per field, 503 with the answers kept in the form.
**Migration**: none; never built.

### Requirement: Submission History and Export

**Reason**: reads a `formSubmission` store that will not exist.
**Migration**: history lists destination objects carrying the form's id.

### Requirement: Existing response data migration is a documented follow-up

**Reason**: replaced by the drain of `enquiry` in this change; legacy `intakeSubmission` objects are drained into tickets by the same command.
**Migration**: `occ pipelinq:enquiry:drain --include-intake-submissions`.
