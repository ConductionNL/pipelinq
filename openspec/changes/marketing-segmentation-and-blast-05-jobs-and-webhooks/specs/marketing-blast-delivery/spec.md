# Marketing Segmentation and Blast — Jobs and Webhooks

## ADDED Requirements

### Requirement: Background Job Dispatches Sending Blasts

A TimedJob SHALL run every 5 minutes, dispatch queued deliveries for
"sending" Blasts, and process queued webhook events without aborting on a
single-Blast failure.

#### Scenario: Job dispatches each sending blast

- **GIVEN** Blasts with status "sending" exist
- **WHEN** BlastSendJob.run() executes
- **THEN** it SHALL call `BlastService.dispatchBlastDeliveries()` for each Blast and update totals
- **AND** a failure on one Blast SHALL NOT abort dispatch of the others

### Requirement: Unsubscribe Propagates Within Minutes

When an unsubscribe webhook is received, the ConsentRecord SHALL be withdrawn
within 60 seconds and queued deliveries skipped at dispatch time.

#### Scenario: Webhook unsubscribe updates consent within 60s

- **GIVEN** a delivered BlastDelivery for a Contact
- **WHEN** a SendGrid webhook POSTs an `unsubscribe` event for that Contact
- **THEN** within 60 seconds the ConsentRecord email channel SHALL have `withdrawnAt` set and `withdrawnReason = "user-unsubscribed"`
- **AND** queued BlastDelivery rows for the Contact SHALL transition to "unsubscribed-before-send"

### Requirement: Bounce Handling Protects Sender Reputation

Hard bounces SHALL immediately withdraw consent; soft bounces SHALL count to
a threshold (default 5) before withdrawal. Bounced Contacts SHALL be excluded
from future email Blasts.

#### Scenario: Hard bounce withdraws consent immediately

- **GIVEN** a sent BlastDelivery
- **WHEN** a hard-bounce webhook is processed
- **THEN** the BlastDelivery SHALL transition to "bounced" with `bounceType = "hard"`
- **AND** the ConsentRecord SHALL be withdrawn with reason "bounce-hard"

#### Scenario: Soft bounce increments counter, withdrawal after threshold

- **GIVEN** a Contact with no prior soft-bounce record
- **WHEN** soft-bounce webhooks are processed for the Contact 5 times
- **THEN** consent SHALL remain active until the 5th, after which it SHALL be withdrawn with reason "bounce-soft-x5"

### Requirement: Click Events Recorded for Attribution

Click webhooks SHALL record `firstClickAt`, append to `clickedUrls`, and
extract the `utm_campaign` parameter for attribution.

#### Scenario: Click event recorded via webhook

- **GIVEN** a BlastDelivery with a tracked link carrying `utm_campaign=blast-...`
- **WHEN** a `click` webhook is processed
- **THEN** the system SHALL set `firstClickAt`, append the URL to `clickedUrls[]`, and extract `utm_campaign` via `AttributionService.recordClick()`
