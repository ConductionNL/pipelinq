## ADDED Requirements

### Requirement: Additional provider webhooks map to the same consent-withdrawal path

Brevo, Mailjet, Mailgun and Postmark webhooks SHALL be verified against a
per-provider signature and mapped to the same bounce/complaint/unsubscribe
handling as the existing SendGrid, SES and Twilio webhooks, with no separate
consent-withdrawal logic.

#### Scenario: Brevo bounce withdraws consent
@e2e exclude provider webhook, no browser trigger: BlastWebhookControllerTest::testBrevoValidSignatureEnqueuesEvent and WebhookProcessorServiceTest assert the same recordConsentWithdrawal(contact, email, bounce-hard, blast) path SendGrid already exercises.

- **GIVEN** a sent BlastDelivery
- **WHEN** a Brevo hard-bounce webhook with a valid signature is processed
- **THEN** the BlastDelivery SHALL transition to "bounced" with
  `bounceType = "hard"` and the ConsentRecord SHALL be withdrawn with reason
  "bounce-hard"

#### Scenario: Mailjet unsubscribe withdraws consent
@e2e exclude provider webhook, no browser trigger: BlastWebhookControllerTest::testMailjetValidSignatureEnqueuesEvent asserts the normalised unsubscribe event reaches enqueueWebhookEvent with the same shape SendGrid produces.

- **GIVEN** a delivered BlastDelivery for a Contact
- **WHEN** a Mailjet `unsub` webhook with a valid signature is processed
- **THEN** the ConsentRecord email channel SHALL have `withdrawnAt` set and
  `withdrawnReason = "user-unsubscribed"`

#### Scenario: Mailgun complaint withdraws consent
@e2e exclude provider webhook, no browser trigger: BlastWebhookControllerTest::testMailgunValidSignatureEnqueuesEvent asserts the HMAC-over-timestamp-plus-token verification and the normalised complaint event.

- **GIVEN** a sent BlastDelivery
- **WHEN** a Mailgun `complained` webhook with a valid signature is processed
- **THEN** the ConsentRecord SHALL be withdrawn with reason "complaint"

#### Scenario: Postmark bounce withdraws consent
@e2e exclude provider webhook, no browser trigger: BlastWebhookControllerTest::testPostmarkValidSignatureEnqueuesEvent asserts Postmark's webhook (verified via the shared X-Pipelinq-Signature header, since Postmark has no native payload signature) reaches the same normalised shape.

- **GIVEN** a sent BlastDelivery
- **WHEN** a Postmark `Bounce` webhook with a valid `X-Pipelinq-Signature`
  header is processed
- **THEN** the BlastDelivery SHALL transition to "bounced" and the
  ConsentRecord SHALL be withdrawn accordingly

#### Scenario: Invalid signature is rejected for every added provider
@e2e exclude provider webhook, no browser trigger: BlastWebhookControllerTest covers a tampered-signature case per provider (testBrevoInvalidSignatureReturns422, testMailjetInvalidSignatureReturns422, testMailgunInvalidSignatureReturns422, testPostmarkInvalidSignatureReturns422), each asserting a 422 response and that enqueueWebhookEvent is never called.

- **WHEN** a Brevo, Mailjet, Mailgun or Postmark webhook is received with a
  missing or invalid signature
- **THEN** the endpoint SHALL return a 422 response and SHALL NOT enqueue any
  webhook event
