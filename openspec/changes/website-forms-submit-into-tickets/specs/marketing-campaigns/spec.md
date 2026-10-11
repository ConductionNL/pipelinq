# marketing-campaigns Delta: website-forms-submit-into-tickets

**Status**: draft
**Scope**: landing-page forms submit into a lead (decision 179).

## RENAMED Requirements

- FROM: `### Requirement: A landing-page submission becomes a contact, a lead and a touchpoint`
- TO: `### Requirement: A landing-page form submits into a lead, with its contact and touchpoint`

## MODIFIED Requirements

### Requirement: A landing-page form submits into a lead, with its contact and touchpoint

A campaign's landing-page form SHALL declare `lead` as its destination, checked by OpenRegister's validator when the page is provisioned. A submit SHALL create the lead in the request. pipelinq's lead-create listener SHALL match a contact by email, case-insensitively, or create one, and SHALL append a `submit` touchpoint with the campaign, channel, UTM block, submission moment and nonce. The listener SHALL be idempotent on the nonce. A submit with no usable email SHALL be refused on the email field.

#### Scenario: A first submission creates a contact, a lead and a touchpoint
- **WHEN** a landing-page form is submitted for an unknown email address
- **THEN** a lead with `firstTouch` and `lastTouch`, a contact and a `submit` touchpoint exist before the response returns

#### Scenario: A redelivered submission creates nothing twice
- **WHEN** the same submit arrives a second time with the same nonce as idempotency key
- **THEN** the response repeats the first lead's reference and no object is written

#### Scenario: A known contact gets a lead, not a duplicate contact
- **WHEN** a submission arrives for `JANE.DOE@EXAMPLE.COM` and a contact exists for `jane.doe@example.com`
- **THEN** that contact is reused and only a lead and a touchpoint are written
