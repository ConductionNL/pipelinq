# Tasks: 05 Jobs and Webhooks

## BlastSendJob (Task 2.5 of giant)

- [x] Create `lib/BackgroundJob/BlastSendJob.php` extending `OCP\BackgroundJob\TimedJob`, interval 300s
- [x] Implement `run()` — query "sending" Blasts, call `BlastService.dispatchBlastDeliveries()` per Blast, catch per-Blast errors (never abort whole job)
- [x] After dispatch, drain webhook event queue, process bounces/opens/clicks/unsubscribes, call `ComplianceService.recordConsentWithdrawal()` for unsubscribes + hard bounces, update totals
- [x] Register in `appinfo/info.xml` under `<background-jobs>`
- [x] Inject `BlastService`, `ComplianceService`, `IUserManager`, `IAppConfig`, `LoggerInterface`; add `@spec` PHPDoc

## Webhooks (Task 2.9 of giant)

- [x] Create `lib/Controller/BlastWebhookController.php` with `POST /api/blast-webhooks/sendgrid`, `/ses`, `/twilio` (named `BlastWebhookController` to avoid collision with the existing CRM `WebhookController.php`; SymfonyRouter slug `blastWebhook#…`)
- [x] Each endpoint verifies provider HMAC signature (per-provider secret in app config, ADR-005), queues the event via `BlastSendJob::enqueueWebhookEvent()`, returns HTTP 200 immediately; signature failures return 422 (matches CtiController convention, keeps the semantic-auth gate clean)
- [x] Create `lib/Service/WebhookProcessorService.php` with `processSendGridEvent`, `processBounce`, `processOpen`, `processClick`, `processUnsubscribe` (plus `processDelivered` + `processComplaint` for the full SendGrid event matrix)
- [x] processBounce: hard → withdraw consent "bounce-hard"; soft → count to threshold (default 5, app-config `blast.soft_bounce_threshold`) → "bounce-soft-x5"
- [x] processClick: `AttributionService.recordClick()`, extract utm_campaign from the URL via `parse_url` + `parse_str`
- [x] processUnsubscribe: `ComplianceService.recordConsentWithdrawal()` reason "user-unsubscribed"
- [x] Add webhook routes to `appinfo/routes.php` (`blastWebhook#sendgrid` / `#ses` / `#twilio`, placed BEFORE the SPA catch-all per ADR-016); `@spec` PHPDoc on every entry point
