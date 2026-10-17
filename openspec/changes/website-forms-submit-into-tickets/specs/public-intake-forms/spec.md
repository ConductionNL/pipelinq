# public-intake-forms Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: resolves the contradiction between the in-app builder (L24 to L499) and the Forms leaf (L587 to L651) under decision 179. One model: pipelinq builds no forms; a form built elsewhere (an external website, Nextcloud Forms, a fleet form authored in buildiq and hosted by portaliq) names its pipelinq destination, is checked against it when published, and a submit creates that destination in the request. No submission store, no retry queue.

## ADDED Requirements

### Requirement: A public pipelinq form names its destination and is checked when published

A public form that feeds pipelinq SHALL declare its own pipelinq destination (`ticket`, `lead`, `contact`, or a contact plus lead as several writes); there is no fixed default (decision 181). In pipelinq this declaration is a `webForm` object. Saving a `webForm` with status `published` SHALL run OpenRegister's form destination validator over each write, and SHALL be refused on any finding, from the first release (decision 181, Q9). It SHALL also be refused when it names no destination, a destination that does not exist, or a key another published form already uses. A draft MAY be saved incomplete. When the check cannot run, the save SHALL be refused. A submit SHALL create the destination in the request. pipelinq SHALL ship no form builder and no submission store of its own.

Every submit is written by pipelinq's system account, a signed-in subject, so the check asks whether the destination grants create to signed-in users, whatever the form's own audience.

#### Scenario: A form into a lead without a title is refused
- **GIVEN** a form into `lead` that maps no field and no fixed value to the required `title`
- **WHEN** its author publishes it
- **THEN** the publish is refused with `required-unmapped` on `title`

#### Scenario: A draft is saved half made
- **GIVEN** a form with status `draft` and no destination yet
- **WHEN** its author saves it
- **THEN** it is saved, and takes no submits

#### Scenario: Two published forms cannot share a key
- **GIVEN** a published form with key `website-support`
- **WHEN** a second form with that key is published
- **THEN** the publish is refused with `key-taken`

@e2e exclude the admin screen for website forms waits for board PqIntakeFormulier (design-system #194, decision 162). The refusal is asserted by PHPUnit through the real creating and updating events (WebFormBindingListenerTest, WebFormBindingCheckTest)

### Requirement: A Nextcloud Forms form feeds pipelinq only through a checked binding

A Nextcloud Forms form SHALL feed pipelinq only through a published `webForm` that names it (`formsFormId`) and maps its question ids onto the destination's properties (decision 181, Q6). When the binding is saved, each mapped question SHALL be judged with its real type and options as Nextcloud Forms holds them now, not as the binding claims; a question that is not on the form, or a binding on an instance without Nextcloud Forms, SHALL refuse the publish. When Nextcloud Forms reports a submit, pipelinq SHALL create the destination from that submit's answers in the same event, idempotent on the submission id. The answers stay in Nextcloud Forms as that app's own record. A refused submit SHALL be logged with the form id, submission id and finding codes, never the answers.

#### Scenario: A bound answer creates its ticket
- **GIVEN** a published binding of Nextcloud Forms form 5 into `ticket`
- **WHEN** someone submits form 5
- **THEN** a ticket exists, made from the mapped answers

#### Scenario: A binding to a question that is not on the form is refused
- **GIVEN** a binding that maps question 99, which form 5 does not have
- **WHEN** its author publishes it
- **THEN** the publish is refused with `question-unknown`

#### Scenario: An answer on an unbound form creates nothing
- **GIVEN** Nextcloud Forms form 6, which no published binding names
- **WHEN** someone submits it
- **THEN** nothing is created in pipelinq

@e2e exclude needs a Nextcloud Forms install on a live instance; asserted by PHPUnit (FormsSubmissionListenerTest, FormsQuestionReaderTest) and owed in the live pass (decision 139)

## MODIFIED Requirements

### Requirement: Forms are provided by the Forms leaf, not an in-app builder

Pipelinq SHALL NOT ship an in-app form builder or store form responses. Form authoring and responses SHALL live in the app that builds the form: Nextcloud Forms through the OpenRegister forms leaf (`integration-forms`), or the fleet form (buildiq-authored, portaliq-hosted). A response SHALL reach pipelinq only as the destination object a checked binding creates. Survey responses are their own destination (see `customer-satisfaction`), not a response store of this capability.

#### Scenario: Bespoke form builder and schemas are removed
- **GIVEN** the migrate-forms-to-forms-leaf change is applied
- **THEN** `src/views/forms/FormBuilder.vue`, the `CnFormBuilder` usage, and the public-submit controller and route of the builder SHALL be removed
- **AND** the `intakeForm` and `intakeSubmission` schemas SHALL be retired
- **AND** form authoring SHALL live in the app that builds the form

#### Scenario: Intake and surveys become NC Forms forms
- **GIVEN** a CRM intake form or survey is needed
- **WHEN** it is created
- **THEN** it SHALL be a Nextcloud Forms form (or a fleet form), never a pipelinq-built one
- **AND** public submission SHALL use that app's public link or embed, which reaches pipelinq only through a checked binding

#### Scenario: A Nextcloud Forms response creates its destination
- **GIVEN** a CRM intake form is needed in Nextcloud Forms
- **WHEN** it is bound to pipelinq
- **THEN** a published `webForm` names it and its destination, and each response creates that destination

## REMOVED Requirements

### Requirement: Form Builder

**Reason**: authoring belongs to buildiq (ADR-085) or Nextcloud Forms; a second builder would need its own destination check.
**Migration**: none; the builder was never built.

### Requirement: Form Data Storage

**Reason**: the `formSubmission` log schema is an in-between store (decision 179).
**Migration**: none; never built. Submission history reads destination objects by their source.

### Requirement: Form Success and Error Handling

**Reason**: its retry queue for server errors is an in-between store. Errors follow hydra `form-destination`: 422 per field, 503 with the answers kept in the form.
**Migration**: none; never built.

### Requirement: Submission History and Export

**Reason**: reads a `formSubmission` store that will not exist.
**Migration**: history lists destination objects carrying the form's key as `source`.

### Requirement: Existing response data migration is a documented follow-up

**Reason**: replaced by the drain of `enquiry` in this change; legacy `intakeSubmission` objects are drained by the same command into the destination their form names, or reported.
**Migration**: `occ pipelinq:enquiry:drain --include-intake-submissions`.

### Requirement: Public survey rendering and submission — documented operations

**Reason**: a generated placeholder describing a builder-era survey runner that is not built; surveys belong to `customer-satisfaction`.
**Migration**: none.

### Requirement: Public survey rendering and submission — results derived from current CRM state

**Reason**: a generated placeholder for the same unbuilt survey runner.
**Migration**: none.

### Requirement: Public survey rendering and submission — defensive handling of absent or invalid input

**Reason**: a generated placeholder for the same unbuilt survey runner.
**Migration**: none.

### Requirement: Forms and surveys UI — documented operations

**Reason**: a generated placeholder for the in-app builder screens this change removes.
**Migration**: none.

### Requirement: Forms and surveys UI — results derived from current CRM state

**Reason**: a generated placeholder for the in-app builder screens this change removes.
**Migration**: none.

### Requirement: Forms and surveys UI — defensive handling of absent or invalid input

**Reason**: a generated placeholder for the in-app builder screens this change removes.
**Migration**: none.
