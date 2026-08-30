# Design: 05 Jobs and Webhooks

## Scope

`lib/BackgroundJob/BlastSendJob.php`, `lib/Controller/WebhookController.php`,
`lib/Service/WebhookProcessorService.php`, plus `appinfo/info.xml`
(background-jobs) and `appinfo/routes.php` (webhook routes). Calls
BlastService (04), ComplianceService (03), AttributionService (04).

## BlastSendJob

Extends `OCP\BackgroundJob\TimedJob`, interval 300s. `run()` queries Blasts
with status "sending", calls `BlastService.dispatchBlastDeliveries()` per
Blast (catch per-Blast errors — never abort the whole job), then drains the
webhook queue and calls `BlastService.updateBlastTotals()`.

## WebhookController + WebhookProcessorService

Endpoints `POST /webhook/sendgrid`, `/webhook/ses`, `/webhook/twilio`. Each
verifies the provider signature (creds via openconnector), queues the event
to app cache / webhook queue, and returns HTTP 200 immediately. The processor
service parses event type and dispatches:
- `processBounce` — set bounceType; hard bounce → `ComplianceService.recordConsentWithdrawal(reason "bounce-hard")`; soft bounce counts to threshold (default 5) → "bounce-soft-x5".
- `processOpen` — set BlastDelivery.openedAt.
- `processClick` — `AttributionService.recordClick()`, extract utm_campaign.
- `processUnsubscribe` — `ComplianceService.recordConsentWithdrawal(reason "user-unsubscribed")`.

## Security / patterns

ADR-005: webhook endpoints are public but signature-verified; user-facing
unsubscribe link validates contact+blast+token. ADR-016: routes only in
`appinfo/routes.php`. Async processing — return 200 fast, process in job.
`@spec` PHPDoc.
