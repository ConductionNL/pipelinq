## ADDED Requirements

### Requirement: A resident asks a question that is not about a dossier

pipelinq SHALL contribute a create action `askQuestion` to the citizen and client audiences, whether or not opencatalogi is installed. It MUST stamp `ticketType: request`, `channel: portal` and the resident's own reference in `portalSubject` server-side, so the question is listed under Mijn vragen. The resident writes only the subject and the question, both required. The action SHALL ask the portal for the `contact-confirmation` mail with `title` as its topic field, so the mail names the subject and never the question.

#### Scenario: Asking from the contact page
- GIVEN a signed-in resident opens the site's question form bound to `askQuestion`
- WHEN she fills in Onderwerp and Uw vraag and sends
- THEN a request ticket with channel `portal` and her reference is filed
- AND it is listed under Mijn vragen
- @e2e exclude declaration contract; covered by PHPUnit `PortalContributionProviderTest::testAResidentAsksAQuestionWithoutADossier` and portaliq's contact form tests
