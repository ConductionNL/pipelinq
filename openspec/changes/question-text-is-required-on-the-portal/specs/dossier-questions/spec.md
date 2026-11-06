## ADDED Requirements

### Requirement: The question text is required on the portal form

The `askAboutDossier` action MUST name `question` in its `requiredFields`, and `replyToQuestion` MUST name `message`, so the portal marks these fields required and refuses an empty one before it sends, as the receivers do.

#### Scenario: Asking about a dossier
- GIVEN a resident opens "Stel een vraag over dit dossier"
- WHEN the form shows
- THEN "Uw vraag" carries no "(niet verplicht)"
- AND sending without a question names the empty field
- @e2e exclude declaration contract; covered by PHPUnit `PortalContributionProviderTest::testCitizenAndClientMayAskAndReply` and portaliq's REQ-SMF-023 tests
