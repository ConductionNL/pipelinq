# Tasks: opt-out-before-send (pipelinq)

Spec only until Ruben approves ConductionNL/hydra#739. Build after integriq ships the events and the new columns (ConductionNL/integriq#2530). All tasks are tier V1.

## 1. The client

- [x] 1.1 Deduplication check: list every reader and writer of `messagingConsentRecord` and `consentRecord` (`git grep -n "messagingConsentRecord\|consentRecord\|recordOptOut\|recordOptIn\|recordListConsent\|recordConsentWithdrawal" lib/`). Every hit is covered by section 2 or 3, or named as out of scope.
  - acceptance: the PR body has the list with a verdict per hit.
- [x] 1.2 `IntegriqConsentClient` with `decide()` and `record()`, string-named events, `class_exists()`, the fail mode.
  - spec_ref: `specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002`
  - files: `lib/Service/IntegriqConsentClient.php`, `tests/Unit/Service/IntegriqConsentClientTest.php`
  - acceptance: absent, unhandled and throwing each refuse `marketing`, `service` and `reminder`, and pass `account`.
  - test: `vendor/bin/phpunit --no-coverage --filter IntegriqConsentClientTest`

## 2. Reads

- [x] 2.1 `ConsentService::canSend()`, `canSendBusinessInitiated()`, `latestState()` delegate when `consent.store=integriq`.
  - files: `lib/Service/ConsentService.php`, its test
  - acceptance: with the flag on, a contact opted out in integriq is refused by `SmsAdapter`. With the flag off, behaviour is unchanged.
  - test: `vendor/bin/phpunit --no-coverage --filter ConsentServiceTest`
- [x] 2.2 `ComplianceService::permitsSend()`, `hasConsentForChannel()`, `hasConsentForList()` delegate. Dunning suppression stays after the integriq answer. `checkSegmentCompliance()` batches.
  - files: `lib/Service/ComplianceService.php`, its test
  - acceptance: the late-payer scenario passes. A segment of 1,200 contacts makes 3 events.
  - test: `vendor/bin/phpunit --no-coverage --filter ComplianceServiceTest`

## 3. Writes

- [x] 3.1 Keyword STOP and START, the consent buttons, list consent and withdrawals write through `record()`. Bounces stay local.
  - spec_ref: `#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003`
  - files: `lib/Service/ConsentService.php`, `lib/Service/ComplianceService.php`, `lib/Service/SmsAdapter.php`, `lib/Service/WhatsAppAdapter.php`, `lib/Controller/MessagingController.php`, `lib/Service/SubscriptionService.php`
  - acceptance: STOP writes no pipelinq record with the flag on.
  - test: `vendor/bin/phpunit --no-coverage --filter "ConsentService|SmsAdapter|WhatsAppAdapter|SubscriptionService"`
- [x] 3.2 Fallback write and a replay job when integriq refuses.
  - files: `lib/BackgroundJob/ReplayConsentToIntegriqJob.php`, `appinfo/info.xml`
  - acceptance: a refused STOP is in pipelinq's store and reaches integriq on the next job run.
  - test: `vendor/bin/phpunit --no-coverage --filter ReplayConsentToIntegriqJob`

## 4. The migration

- [x] 4.1 Repair step `MigrateConsentToIntegriq` with the mapping, `legacyRef`, counts, and the flag only on a clean run.
  - spec_ref: `#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001`
  - files: `lib/Repair/MigrateConsentToIntegriq.php`, `appinfo/info.xml`, its test
  - acceptance: the five migration scenarios pass. Test with OpenRegister that the old schemas still validate as read-only history.
  - test: `vendor/bin/phpunit --no-coverage --filter MigrateConsentToIntegriq`
- [x] 4.2 Rollback note in `docs/`: how to set `consent.store=pipelinq` and replay integriq's newer rows.
  - files: `docs/Features/consent.md` (the repo's docs folder is `Features`)

## 5. Senders that checked nothing

- [x] 5.1 `AppointmentEmailService` asks as `reminder`. The booking shows a refusal.
  - files: `lib/Service/AppointmentEmailService.php`, `lib/Controller/BookingAdminController.php`
  - test: `vendor/bin/phpunit --no-coverage --filter AppointmentEmailService`
- [x] 5.2 `EmailFallbackSender` asks with the Berichtenbox message's category.
  - files: `lib/Service/EmailFallbackSender.php`, `lib/Service/BerichtenboxService.php`
  - test: `vendor/bin/phpunit --no-coverage --filter EmailFallbackSender`

## 6. The link

- [x] 6.1 Segment blasts get integriq's link. List blasts keep pipelinq's list link. `applyHeaders()` delegates to OpenRegister's `UnsubscribeHeaders`.
  - spec_ref: `#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004`
  - files: `lib/Service/Marketing/MailTransportService.php`, `lib/Service/Marketing/Transport/InstanceMailerTransport.php`
  - test: `vendor/bin/phpunit --no-coverage --filter MailTransportService`
- [x] 6.2 `ListPublicController` POST writes through `record()`.
  - files: `lib/Controller/ListPublicController.php`, `lib/Service/SubscriptionService.php`
  - test: `vendor/bin/phpunit --no-coverage --filter ListPublicController`

## 7. Erasure

- [x] 7.1 `onContactDeleted()` dispatches `erase-contact` to integriq instead of deleting the opt-out (Ruben, 2026-10-05).
  - spec_ref: `specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005`
  - files: `lib/Service/ClientManagementIntegration.php`, its test
  - test: `vendor/bin/phpunit --no-coverage --filter ClientManagementIntegration`
- [x] 7.2 Conversation answers go out as `reply` with `inReplyTo`.
  - files: `lib/Service/SmsAdapter.php`, `lib/Service/WhatsAppAdapter.php`, `lib/Service/MessagingService.php`
  - test: `vendor/bin/phpunit --no-coverage --filter "SmsAdapter|WhatsAppAdapter"`

## 8. Verify

- [ ] 8.1 (partly done: plob-live run-3d shows a pipelinq opt-out reaching integriq and refusing pipelinq's next SMS; the NotifyNL leg and the "stop everything" link leg were not run here) Live check with integriq: send STOP to pipelinq, then send an integriq NotifyNL SMS to the same number. It is refused. Then follow an integriq unsubscribe link with "stop everything" and run a pipelinq service SMS to that person. It is refused.
- [x] 8.2 Verify the Nextcloud integration with the real `OCP\EventDispatcher\IEventDispatcher` and `OCP\Mail\IMailer`.
- [x] 8.3 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` once, then `npm run lint`.
