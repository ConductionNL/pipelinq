## MODIFIED Requirements

### Requirement: Blast Cannot Send Without Lawful Basis

@e2e exclude the preflight runs inside ComplianceService against a whole segment's ConsentRecords and returns a machine-readable missing-contacts list to the caller; the send it gates dispatches through openconnector, which the CI instance does not install (.github/workflows/code-quality.yml pins `additional-apps` to openregister only), so no browser run can reach the blocked-send state. Asserted by tests/Unit/Service/ComplianceServiceTest.php (testCheckSegmentComplianceMissingContacts, testCheckSegmentComplianceAllCompliant, testHasConsentForChannelImportedNotSatisfying, testPreflightBlastReturnsValidWhenAllChecksPass, testHasConsentForListConfirmedSubscription, testHasConsentForListWithdrawn, testSoftOptInBasisSatisfiesConsent), tests/Unit/Service/BlastServiceTest.php (testSendBlastQueuesCompliantSkipsNonCompliant, testSendBlastFailsClosedWhenComplianceUnavailable) and tests/Integration/BlastWorkflowTest.php (testAllCompliantSegmentQueuesAllMembers).

A Blast SHALL NOT be sent to any Contact that lacks a ConsentRecord for the
target channel with lawful-basis set. The system SHALL block the send and
offer remediation options.

A ConsentRecord MAY be scoped to a mailing list through `listId`. A list-scoped
record gates sends to that list only; a record with no `listId` remains the
channel-wide record and is the one consulted when a Blast targets a Segment. A
confirmed subscription SHALL be consent for its own list, and `soft-opt-in`
SHALL join `consent`, `legitimate-interest` and `contract` as a lawful basis
that permits a send.

#### Scenario: Send blocked with missing consent list

- **GIVEN** a Segment matching 1,000 Contacts of which 12 have no ConsentRecord for the email channel
- **WHEN** compliance is checked for a send
- **THEN** `ComplianceService.checkSegmentCompliance()` SHALL report the 12 missing contact IDs and `compliant: false`
- **AND** the send SHALL be blocked while the Blast remains in draft status

#### Scenario: Imported contacts do not satisfy consent

- **GIVEN** Contacts bulk-imported with consentSource = "imported"
- **WHEN** compliance is checked
- **THEN** lawful-basis "imported" SHALL NOT satisfy consent gating
- **AND** the audit log SHALL note that "imported" does not permit marketing sends

#### Scenario: A list-scoped record does not open the channel

- **GIVEN** a Contact whose only ConsentRecord carries a `listId`
- **WHEN** `hasConsentForChannel(contactId, "email")` is called with no list named
- **THEN** it SHALL return false, because the channel-wide record does not exist
- **AND** `hasConsentForList(contactId, listId, "email")` SHALL return true

## ADDED Requirements

### Requirement: Soft Opt-In Is Only Consent With the Objection Recorded

A ConsentRecord with lawful basis `soft-opt-in` SHALL carry evidence stating that an objection was offered and when. A record that claims `soft-opt-in` without that evidence SHALL NOT satisfy consent gating, and the audit log SHALL name the missing evidence.

#### Scenario: Soft opt-in without evidence does not permit a send

- **GIVEN** a ConsentRecord with lawful basis `soft-opt-in` and no `evidence.objectionOffered`
- **WHEN** consent is checked for that contact and list
- **THEN** it SHALL return false
- **AND** the audit log SHALL note that soft opt-in needs the objection recorded

#### Scenario: Soft opt-in with evidence permits a send

- **GIVEN** a ConsentRecord with lawful basis `soft-opt-in` whose evidence records the objection offered and its date
- **WHEN** consent is checked for that contact and list
- **THEN** it SHALL return true
