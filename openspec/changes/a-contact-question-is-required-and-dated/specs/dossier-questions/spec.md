# dossier-questions Delta: a-contact-question-is-required-and-dated

## ADDED Requirements

### Requirement: A question asked on the contact page needs its text and is dated

The `askQuestion` action SHALL name its subject and its question in `requiredFields`, so the portal asks both as required, and SHALL name `occurredAt` as its `createdAtField`, so the ticket carries the moment it was asked and Mijn vragen shows it under "Gesteld".

#### Scenario: The question is required
@e2e exclude declaration contract; covered by PortalContributionProviderTest::testAContactQuestionNeedsItsTextAndIsDated
- **GIVEN** a resident opens the question form on the contact page
- **WHEN** the form is drawn
- **THEN** "Uw vraag" carries no "niet verplicht" mark and an empty question is refused

#### Scenario: The question is dated
@e2e exclude the stamp is written by portaliq; covered by PortalContributionProviderTest and portaliq CreateBodyTest
- **GIVEN** a resident sends a question from the contact page
- **WHEN** she opens Mijn vragen
- **THEN** "Gesteld" shows the day she asked it
