---
kind: code
depends_on: [marketing-segmentation-and-blast-04-blast-attribution-services]
chain:
  - marketing-segmentation-and-blast-01-schema-and-seed-config
  - marketing-segmentation-and-blast-02-segment-service
  - marketing-segmentation-and-blast-03-compliance-service
  - marketing-segmentation-and-blast-04-blast-attribution-services
  - marketing-segmentation-and-blast-05-jobs-and-webhooks
  - marketing-segmentation-and-blast-06-rest-controllers
  - marketing-segmentation-and-blast-07-segment-blast-views
  - marketing-segmentation-and-blast-08-performance-dashboard
  - marketing-segmentation-and-blast-09-unit-integration-tests
  - marketing-segmentation-and-blast-10-docs
  - marketing-segmentation-and-blast-11-quality-verification
---

# Proposal: Marketing Segmentation and Blast — 05 Jobs and Webhooks

Member **5 of 11** in the `marketing-segmentation-and-blast` chain.
Predecessor: `marketing-segmentation-and-blast-04-blast-attribution-services`.
This member adds the background job that drives throttled dispatch and the
webhook ingestion path for bounce/open/click/unsubscribe events.

## Why (carried from the giant)

Unsubscribe and bounce events must propagate back to Pipelinq within minutes,
not the 24-hour delay of the Mailchimp export-import cycle. The BlastSendJob
dispatches queued deliveries every 5 minutes and processes provider webhook
events into BlastDelivery state and ConsentRecord withdrawals.

## What this member does

- `lib/BackgroundJob/BlastSendJob.php` — TimedJob (300s) that dispatches
  "sending" Blasts and processes queued webhook events.
- `lib/Controller/WebhookController.php` + `lib/Service/WebhookProcessorService.php`
  — provider webhook endpoints (SendGrid/SES/Twilio) that queue events and
  process bounce/open/click/unsubscribe into delivery state + consent
  withdrawal (via ComplianceService) + click attribution (via
  AttributionService).
- Registers the job in `appinfo/info.xml` and webhook routes in
  `appinfo/routes.php`.

## Out of scope

The CRUD REST controllers (member 06); the live monitor + dashboard views
(members 07, 08).
