# Keep consent in integriq and ask integriq before you send

Part of the hydra change `opt-out-before-send` (ConductionNL/hydra#739). That change holds the fleet contract, the sender table and Ruben's decisions of 2026-10-05. integriq's side is ConductionNL/integriq#2530. This is pipelinq's share.

## Why

pipelinq keeps its own consent, and integriq keeps its own opt-outs. Neither reads the other.

- `ConsentService` (`lib/Service/ConsentService.php`) holds SMS and WhatsApp consent as `messagingConsentRecord` objects. `SmsAdapter` (`lib/Service/SmsAdapter.php:162`) and `WhatsAppAdapter` (`lib/Service/WhatsAppAdapter.php:317-320`) honour it.
- `ComplianceService` (`lib/Service/ComplianceService.php`) holds marketing consent as `consentRecord` objects. `permitsSend()` (`:197`), `hasConsentForChannel()` (`:401`) and `hasConsentForList()` (`:428`) gate blasts, journeys and lists.
- A person who unsubscribes from an integriq link is still messaged by pipelinq. A person who sends STOP to pipelinq is still messaged by dossiq and integriq.
- Appointment mail (`lib/Service/AppointmentEmailService.php:427`) and the Berichtenbox email fallback (`lib/Service/EmailFallbackSender.php:123`) check nothing.

Ruben decided on 2026-10-05: one store, in integriq. pipelinq asks integriq.

## What changes

- **The records move.** A repair step migrates both consent stores into integriq's `integriq_opt_outs` table through `OptOutChangeRequestedEvent`, idempotent by `legacyRef`. The mapping is in hydra's design, section 7.
- **The reads delegate.** `ConsentService::canSend()`, `canSendBusinessInitiated()`, `latestState()` and `ComplianceService::permitsSend()`, `hasConsentForChannel()`, `hasConsentForList()` ask integriq through `OutboundSendDecisionRequestedEvent`. Their callers stay unchanged.
- **The writes delegate.** STOP and START keywords, the messaging consent buttons, list confirmations, the preference centre and withdrawals write to integriq.
- **Appointment mail and the email fallback ask too**, as `reminder` and as the category of the original message.
- **Every non-exempt message carries integriq's link.** Segment blasts get one too. Today they have none (`lib/Service/Marketing/MailTransportService.php:233-235`).
- **What stays in pipelinq:** dunning suppression (`ComplianceService::isSuppressed`, `:228`), bounce handling (`lib/Service/WebhookProcessorService.php:297`, `:311`), keyword parsing, list membership (`subscription` objects from `marketing-lists-and-double-opt-in`), and the old consent records as read-only history.
- **A cutover flag.** `consent.store` stays `pipelinq` until the migration finishes without error, then becomes `integriq`.

## Capabilities

### New capabilities

- `consent-in-integriq`: pipelinq reads and writes consent and opt-outs through integriq.

## Impact

- `lib/Service/ConsentService.php`, `lib/Service/ComplianceService.php`, `lib/Service/SubscriptionService.php`, `lib/Controller/MessagingController.php`, `lib/Controller/ListPublicController.php`
- `lib/Service/SmsAdapter.php`, `lib/Service/WhatsAppAdapter.php`, `lib/Service/AppointmentEmailService.php`, `lib/Service/EmailFallbackSender.php`, `lib/Service/Marketing/MailTransportService.php`
- `lib/Service/ClientManagementIntegration.php` (contact erasure keeps the opt-out, Ruben 2026-10-05)
- A new repair step `lib/Repair/MigrateConsentToIntegriq.php`
- **pipelinq now needs integriq for every message that is not exempt.** Without integriq, marketing, SMS, WhatsApp and reminders stop. Password reset and account mail (`lib/Service/Portal/PortalMailService.php:111`) keep working.
- The `marketing-lists-and-double-opt-in` change (#1770) is fully ticked. Its list consent moves with the rest. Its public endpoints stay on pipelinq, because old mail names them in `List-Unsubscribe` headers.
- Feature tier: V1.
- Procest/dossiq bridge: not affected.

## Reuse

ADR-011 check: phone normalisation is integriq's `PhoneNumberValidator::toE164()`, used inside integriq's listener. pipelinq's own `CtiContactMatcher` normalisation (`lib/Service/CtiContactMatcher.php:290`) is for call matching and is not reused here.

## Rollback

Set `consent.store` back to `pipelinq`. The old records were never deleted, and writes made after the cutover stay in integriq, so a rollback loses no wish but may miss the newest ones in pipelinq's view. The task list says how to replay them.
