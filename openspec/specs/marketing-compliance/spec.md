---
status: in-progress
---

# marketing-compliance Specification

**OpenSpec changes**:
- `marketing-lists-and-double-opt-in` (in progress) — a ConsentRecord may be scoped to a mailing list through `listId`, a confirmed subscription is consent for its own list, and `soft-opt-in` joins the lawful bases that permit a send, but only with the objection recorded. See [marketing-lists](../marketing-lists/spec.md).

## Purpose
Enforces lawful-basis consent and anti-spam rules before a marketing blast can be sent. Blocks sends to contacts that lack a consent record for the target channel, requires an unsubscribe token and physical-address block on email templates, and propagates consent withdrawals from unsubscribes and hard bounces so queued deliveries are skipped.
## Requirements
### Requirement: Blast Cannot Send Without Lawful Basis

@e2e exclude the preflight runs inside ComplianceService against a whole segment's ConsentRecords and returns a machine-readable missing-contacts list to the caller; the send it gates dispatches through openconnector, which the CI instance does not install (.github/workflows/code-quality.yml pins `additional-apps` to openregister only), so no browser run can reach the blocked-send state. Asserted by tests/Unit/Service/ComplianceServiceTest.php (testCheckSegmentComplianceMissingContacts, testCheckSegmentComplianceAllCompliant, testHasConsentForChannelImportedNotSatisfying, testPreflightBlastReturnsValidWhenAllChecksPass), tests/Unit/Service/BlastServiceTest.php (testSendBlastQueuesCompliantSkipsNonCompliant, testSendBlastFailsClosedWhenComplianceUnavailable) and tests/Integration/BlastWorkflowTest.php (testAllCompliantSegmentQueuesAllMembers).

A Blast SHALL NOT be sent to any Contact that lacks a ConsentRecord for the
target channel with lawful-basis set. The system SHALL block the send and
offer remediation options.

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

### Requirement: Unsubscribe Footer Enforced on Email Templates

Every email CampaignTemplate SHALL contain the unsubscribe token
`{{unsubscribe_link}}` and a physical-address block. Save SHALL be rejected
if either is missing.

#### Scenario: Save rejected if unsubscribe token missing

- **GIVEN** a CampaignTemplate with channel = "email" whose bodyHtml lacks `{{unsubscribe_link}}`
- **WHEN** `ComplianceService.validateTemplate()` runs
- **THEN** it SHALL return an error requiring the `{{unsubscribe_link}}` token and the template SHALL NOT be persisted

#### Scenario: Save rejected if physical address missing

- **GIVEN** a CampaignTemplate with channel = "email" containing `{{unsubscribe_link}}` but no physical-address block
- **WHEN** `validateTemplate()` runs
- **THEN** it SHALL return a CAN-SPAM physical-address error

#### Scenario: SMS templates do not require unsubscribe footer

- **GIVEN** a CampaignTemplate with channel = "sms"
- **WHEN** `validateTemplate()` runs
- **THEN** the unsubscribe-token and physical-address validations SHALL NOT apply

### Requirement: Consent Withdrawal Propagates

@e2e exclude withdrawal arrives over a provider webhook (`POST /api/blast-webhooks/{sendgrid,ses,twilio}`, signature-verified) and its effect is a ConsentRecord field plus a status flip on already-queued BlastDelivery rows — none of which any pipelinq screen renders, and there is no UI affordance that withdraws consent. Asserted by tests/Unit/Service/ComplianceServiceTest.php (testRecordConsentWithdrawalUpdatesRecordAndTransitionsDeliveries, testRecordConsentWithdrawalKeepsFirstWithdrawalTimestamp, testRecordConsentWithdrawalCreatesAuditLedgerWhenNoRecordExists, testHasConsentForChannelWithdrawnConsent, testHasConsentForChannelActiveConsent, testHasConsentForChannelNoRecordReturnsFalse) and end to end by tests/Integration/BlastWorkflowTest.php (testWithdrawalTransitionsQueuedDeliveriesEndToEnd).

When an unsubscribe or hard bounce occurs, the system SHALL withdraw the
ConsentRecord via `recordConsentWithdrawal()` and cause queued deliveries
for that contact to be skipped.

#### Scenario: Withdrawal updates consent and skips queued deliveries

- **GIVEN** a ConsentRecord for a Contact's email channel
- **WHEN** `recordConsentWithdrawal(contactId, "email", "user-unsubscribed")` is called
- **THEN** the ConsentRecord SHALL have `withdrawnAt` set and `withdrawnReason = "user-unsubscribed"`
- **AND** queued BlastDelivery rows for that Contact SHALL be transitioned to "unsubscribed-before-send"

#### Scenario: hasConsentForChannel reflects withdrawal

- **GIVEN** a Contact whose email ConsentRecord has `withdrawnAt` set
- **WHEN** `hasConsentForChannel(contactId, "email")` is called
- **THEN** it SHALL return false

