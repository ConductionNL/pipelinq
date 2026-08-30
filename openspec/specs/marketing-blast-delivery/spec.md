---
status: done
---

# marketing-blast-delivery Specification

## Purpose
Drives blast delivery via a background job that dispatches queued sends every few minutes and processes provider webhooks without one blast's failure aborting the others. It propagates unsubscribes to consent records within a minute, handles hard and soft bounces to protect sender reputation, and records click events (first-click time, clicked URLs, UTM campaign) for attribution.
## Requirements
### Requirement: Background Job Dispatches Sending Blasts

A TimedJob SHALL run every 5 minutes, dispatch queued deliveries for
"sending" Blasts, and process queued webhook events without aborting on a
single-Blast failure.

#### Scenario: Job dispatches each sending blast
@e2e exclude cron TimedJob with no UI trigger: BlastSendJobTest::testDispatchSendingBlastsCallsServicePerBlast asserts one dispatchBlastDeliveries + updateBlastTotals per sending blast, and ::testDispatchContinuesOnPerBlastFailure asserts a throwing blast does not abort the run. Both clauses of the scenario are covered; nothing in a browser starts a cron job.

- **GIVEN** Blasts with status "sending" exist
- **WHEN** BlastSendJob.run() executes
- **THEN** it SHALL call `BlastService.dispatchBlastDeliveries()` for each Blast and update totals
- **AND** a failure on one Blast SHALL NOT abort dispatch of the others

### Requirement: Unsubscribe Propagates Within Minutes

When an unsubscribe webhook is received, the ConsentRecord SHALL be withdrawn
within 60 seconds and queued deliveries skipped at dispatch time.

#### Scenario: Webhook unsubscribe updates consent within 60s
@e2e exclude provider webhook, no browser trigger: WebhookProcessorServiceTest::testUnsubscribeWithdrawsConsent and BlastWorkflowTest::testWithdrawalTransitionsQueuedDeliveriesEndToEnd assert the state transition (queued -> unsubscribed-before-send, sent rows untouched, consentRecord.withdrawnAt set, withdrawnReason=user-unsubscribed). NOT asserted: the literal 60-second latency, which follows from the 5-minute drain cadence rather than from a test.

- **GIVEN** a delivered BlastDelivery for a Contact
- **WHEN** a SendGrid webhook POSTs an `unsubscribe` event for that Contact
- **THEN** within 60 seconds the ConsentRecord email channel SHALL have `withdrawnAt` set and `withdrawnReason = "user-unsubscribed"`
- **AND** queued BlastDelivery rows for the Contact SHALL transition to "unsubscribed-before-send"

### Requirement: Bounce Handling Protects Sender Reputation

Hard bounces SHALL immediately withdraw consent; soft bounces SHALL count to
a threshold (default 5) before withdrawal. Bounced Contacts SHALL be excluded
from future email Blasts.

#### Scenario: Hard bounce withdraws consent immediately
@e2e exclude provider webhook, no browser trigger: WebhookProcessorServiceTest asserts recordConsentWithdrawal(contact, email, bounce-hard, blast) exactly once and the delivery row at status=bounced / bounceType=hard.

- **GIVEN** a sent BlastDelivery
- **WHEN** a hard-bounce webhook is processed
- **THEN** the BlastDelivery SHALL transition to "bounced" with `bounceType = "hard"`
- **AND** the ConsentRecord SHALL be withdrawn with reason "bounce-hard"

#### Scenario: Soft bounce increments counter, withdrawal after threshold
@e2e exclude provider webhook, no browser trigger: WebhookProcessorServiceTest drives five soft-bounce events and asserts recordConsentWithdrawal fires exactly once, with reason bounce-soft-x5 — i.e. the counter and the threshold, not just the end state.

- **GIVEN** a Contact with no prior soft-bounce record
- **WHEN** soft-bounce webhooks are processed for the Contact 5 times
- **THEN** consent SHALL remain active until the 5th, after which it SHALL be withdrawn with reason "bounce-soft-x5"

### Requirement: Click Events Recorded for Attribution

Click webhooks SHALL record `firstClickAt`, append to `clickedUrls`, and
extract the `utm_campaign` parameter for attribution.

#### Scenario: Click event recorded via webhook
@e2e exclude provider webhook, no browser trigger: WebhookProcessorServiceTest::testClickEventExtractsUtmCampaign asserts the utmCampaign extraction and a single recordClick; AttributionServiceTest::testRecordClickSetsFirstClickAtAndAppendsUrl asserts firstClickAt, the appended URL and the status transition to clicked.

- **GIVEN** a BlastDelivery with a tracked link carrying `utm_campaign=blast-...`
- **WHEN** a `click` webhook is processed
- **THEN** the system SHALL set `firstClickAt`, append the URL to `clickedUrls[]`, and extract `utm_campaign` via `AttributionService.recordClick()`

