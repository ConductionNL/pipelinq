# marketing-campaigns Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: landing-page forms submit into a lead (decision 179).

## RENAMED Requirements

- FROM: `### Requirement: A landing-page submission becomes a contact, a lead and a touchpoint`
- TO: `### Requirement: A landing-page form submits into a lead, with its contact and touchpoint`

## MODIFIED Requirements

### Requirement: A landing-page form submits into a lead, with its contact and touchpoint

A campaign's landing-page form SHALL declare `lead` as its destination, checked by OpenRegister's validator when the page is provisioned. The landing-page request SHALL carry that declaration as `form.destination`: the register, the schema, which answer writes which lead property, and the values every submit writes (title, source, campaign). A submit SHALL create the lead in the request. The lead SHALL keep what the visitor typed (`submitterName`, `submitterEmail`, `submitterOrganisation`) and what the page knew (`nonce`, `submittedAt`, `pageId`, `referrer`), so it is answerable before anyone matched a contact. pipelinq's lead-create listener SHALL match a contact by email, case-insensitively, or create one, SHALL match or create a client only when the visitor named an organisation, and SHALL append a `submit` touchpoint with the campaign, channel, UTM block, submission moment and nonce. The listener SHALL be idempotent on the nonce, SHALL act on any lead that carries those properties whichever form made it, and SHALL never block the lead. A submit with no usable email SHALL be refused on the email field.

A form made before forms named a destination SHALL get `lead` as its destination once, by a repair step that writes the same declaration a new form carries. The step SHALL leave a form that already names a destination alone, and SHALL touch only forms pipelinq asked for.

#### Scenario: A first submission creates a contact, a lead and a touchpoint
- **WHEN** a landing-page form is submitted for an unknown email address
- **THEN** a lead with `firstTouch` and `lastTouch`, a contact and a `submit` touchpoint exist before the response returns

#### Scenario: A redelivered submission creates nothing twice
- **WHEN** the same submit arrives a second time with the same nonce as idempotency key
- **THEN** the response repeats the first lead's reference and no object is written

#### Scenario: A known contact gets a lead, not a duplicate contact
- **WHEN** a submission arrives for `JANE.DOE@EXAMPLE.COM` and a contact exists for `jane.doe@example.com`
- **THEN** that contact is reused and only a lead and a touchpoint are written

#### Scenario: A new landing page names its destination
- **WHEN** a campaign asks for its landing page
- **THEN** the request's form carries `destination` with register `pipelinq`, schema `lead`, a property for every form field, and the campaign as a fixed value

#### Scenario: An existing form gets its destination once
- **GIVEN** a hosted form pipelinq asked for that names no destination, and one an administrator pointed at `ticket`
- **WHEN** the repair step runs, and runs again
- **THEN** the first form submits into `lead` with its campaign fixed, the second still submits into `ticket`, and the second run changes nothing

#### Scenario: A visitor who names no organisation gets no client
- **WHEN** a landing-page form is submitted without an organisation
- **THEN** the lead has a contact and no client, and no client is created
