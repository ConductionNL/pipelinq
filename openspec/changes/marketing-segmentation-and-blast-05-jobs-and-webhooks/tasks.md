# Tasks: 05 Jobs and Webhooks

## BlastSendJob (Task 2.5 of giant)

- [ ] Create `lib/BackgroundJob/BlastSendJob.php` extending `OCP\BackgroundJob\TimedJob`, interval 300s
- [ ] Implement `run()` — query "sending" Blasts, call `BlastService.dispatchBlastDeliveries()` per Blast, catch per-Blast errors (never abort whole job)
- [ ] After dispatch, drain webhook event queue, process bounces/opens/clicks/unsubscribes, call `ComplianceService.recordConsentWithdrawal()` for unsubscribes + hard bounces, update totals
- [ ] Register in `appinfo/info.xml` under `<background-jobs>`
- [ ] Inject `BlastService`, `ComplianceService`, `IUserManager`, `IAppConfig`, `LoggerInterface`; add `@spec` PHPDoc

## Webhooks (Task 2.9 of giant)

- [ ] Create `lib/Controller/WebhookController.php` with `POST /webhook/sendgrid`, `/webhook/ses`, `/webhook/twilio`
- [ ] Each endpoint verifies provider signature (creds via openconnector), queues event async, returns HTTP 200 immediately
- [ ] Create `lib/Service/WebhookProcessorService.php` with `processSendGridEvent`, `processBounce`, `processOpen`, `processClick`, `processUnsubscribe`
- [ ] processBounce: hard → withdraw consent "bounce-hard"; soft → count to threshold (default 5) → "bounce-soft-x5"
- [ ] processClick: `AttributionService.recordClick()`, extract utm_campaign
- [ ] processUnsubscribe: `ComplianceService.recordConsentWithdrawal()` reason "user-unsubscribed"
- [ ] Add webhook routes to `appinfo/routes.php`; add `@spec` PHPDoc
