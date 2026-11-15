## ADDED Requirements

### Requirement: A Blast May Target a Mailing List

A Blast SHALL name either a Segment or a mailing list as its audience, and SHALL be refused when it names neither. When it names a list the audience SHALL be the list's confirmed subscriptions whose list consent stands, resolved at send time exactly as a Segment is, so the recipients are never a materialised copy.

#### Scenario: A blast targeting a list queues its confirmed subscribers

@e2e exclude the queueing runs inside `BlastService::sendBlast()` and dispatches through `OCA\OpenConnector\Service\SourceService`, which the CI instance has no openconnector to hold — .github/workflows/code-quality.yml pins `additional-apps` to openregister only — so no browser run reaches a send. Asserted by tests/Unit/Service/SubscriptionServiceTest.php (testOnlyConfirmedMembersReachABlast, testConfirmedMemberWithWithdrawnConsentIsSkipped).

- **GIVEN** a Blast whose `listId` names a mailing list with two confirmed subscriptions
- **WHEN** the Blast is sent
- **THEN** two BlastDeliveries SHALL be queued, one per confirmed subscriber
- **AND** each delivery SHALL carry the address stored on the subscription

#### Scenario: A blast with no audience is refused

@e2e exclude the refusal is a guard at the top of `BlastService::sendBlast()` returning the summary status `no-audience`; the wizard's audience step cannot submit an empty audience, so no browser path reaches it, and the send it guards dispatches through openconnector, which the CI instance does not install. Asserted by tests/Unit/Service/BlastServiceTest.php (testSendBlastWithoutAudienceIsRefused).

- **GIVEN** a Blast with neither a `segmentId` nor a `listId`
- **WHEN** the Blast is sent
- **THEN** the send SHALL be refused with status `no-audience` and nothing SHALL be queued
