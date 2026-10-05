# Design: keep consent in integriq and ask integriq before you send

The fleet contract, the mapping of consent types (section 7), the category list, the fail mode and Ruben's decisions of 2026-10-05 live in hydra's `openspec/changes/opt-out-before-send/design.md` (ConductionNL/hydra#739). This file covers pipelinq. Lines are at `development` `59f93beb`.

## 1. One client for integriq

A new `IntegriqConsentClient` in `lib/Service/` holds both events.

- `decide(channel, category, requiresConsent, recipients, correlationId)` dispatches `OCA\Integriq\Event\OutboundSendDecisionRequestedEvent`.
- `record(address, contactRef, state, scope, channel, ref, lawfulBasis, evidence, source, legacyRef)` dispatches `OCA\Integriq\Event\OptOutChangeRequestedEvent`.
- Both name the class as a string and check `class_exists()` first. pipelinq already depends on OpenRegister this way and integriq is optional, so no integriq class appears in a type or a `use` statement.
- Absent, unhandled or throwing: `decide()` refuses non-exempt categories with `authority-unavailable`. `record()` returns a refusal, and the caller keeps the wish in pipelinq's own store (section 4), so no STOP is lost.

The address comes from the contact. Email is the contact's email. SMS and WhatsApp use the contact's phone, sent as stored; integriq normalises it. `contactRef` is the contact UUID.

## 2. The reads delegate

The public methods keep their signatures, so their callers do not change.

| Method | Today | After the cutover |
|---|---|---|
| `ConsentService::canSend()` (`lib/Service/ConsentService.php:114`) | latest record, absent is allowed (`:120-124`) | `decide(channel, 'service', false, …)` |
| `ConsentService::canSendBusinessInitiated()` (`:146`) | latest must be `opted-in` | `decide(channel, 'service', true, …)` |
| `ConsentService::latestState()` (`:174`) | latest state | a read of integriq's row state through the decision: `opted-out` when refused as `opted-out`, `opted-in` when a consent matched, else `unknown` |
| `ComplianceService::permitsSend()` (`lib/Service/ComplianceService.php:197`) | consent, then dunning suppression | `decide(channel, intent === 'service' ? 'service' : 'marketing', intent !== 'service', …)`, then dunning suppression stays local |
| `ComplianceService::hasConsentForChannel()` (`:401`) | `recordPermitsSend()` (`:453`) | `decide(channel, 'marketing', true, …)` |
| `ComplianceService::hasConsentForList()` (`:428`) | list record | `decide(channel, 'marketing', true, [{…, listRef}])` |

Callers left untouched: `SmsAdapter.php:162`, `WhatsAppAdapter.php:317-320`, `BlastService::resolveAudience()` (`lib/Service/BlastService.php:1034`), `JourneyStepRunner.php:158`, `SubscriptionQueryService.php:111`, `MessagingController.php:242`, `MessagingService.php:177-178`.

`checkSegmentCompliance()` (`ComplianceService.php:296`) loops contacts. After the cutover it collects their addresses and asks integriq in one batch per 500, so a blast does not make one event per contact.

`recordPermitsSend()` and `objectionWasOffered()` (`:453`, `:505`) move to integriq as its consent rules. pipelinq keeps them only for the pre-cutover path.

## 3. The writes delegate

| Today | After the cutover |
|---|---|
| `ConsentService::recordOptOut()` (`:235`), from STOP (`SmsAdapter.php:429-430`, `WhatsAppAdapter.php:655-656`) and the UI (`MessagingController.php:280`) | `record(…, 'opted-out', 'channel', channel, …, source)` |
| `ConsentService::recordOptIn()` (`:205`), from START and the UI (`MessagingController.php:270`) | `record(…, 'opted-in', 'channel', channel, …, legalBasis, evidence)` |
| `ComplianceService::recordListConsent()` (`:535`), from `SubscriptionService.php:274`, `:479`, `:554` | `record(…, 'opted-in', 'list', channel, listId, lawfulBasis, evidence)` |
| `ComplianceService::recordConsentWithdrawal()` (`:1003`), reasons `user-unsubscribed`, `complaint`, `admin-removed` | `record(…, 'opted-out', listId ? 'list' : 'channel', …)` |
| the same, reasons `bounce-hard`, `bounce-soft-x5` (`WebhookProcessorService.php:297`, `:311`) | stays in pipelinq: a bounce is deliverability, not a wish |

`transitionQueuedDeliveries()` still runs after a withdrawal, so queued blast deliveries are cancelled as now.

## 4. The cutover

1. The repair step `MigrateConsentToIntegriq` runs on upgrade. It needs integriq with the events. Without them, it logs and does nothing, and the flag stays `pipelinq`.
2. It reads every `messagingConsentRecord` and `consentRecord`, applies the mapping in hydra's design section 7, and calls `record()` with `legacyRef` = the pipelinq object UUID. integriq skips a known `legacyRef`, so a second run writes nothing.
3. It reports counts: migrated, skipped as bounce, skipped without an address, refused.
4. Only when no record was refused does it set `consent.store=integriq`. A refusal leaves the flag as `pipelinq` and the next run retries.
5. Before the flag flips, every read and write uses pipelinq's own store, as today.
6. If `record()` is refused after the cutover, the wish is written to pipelinq's store as before and a background job replays it to integriq. A STOP is never dropped.

## 5. The senders that checked nothing

| Sender | Ask before | Category |
|---|---|---|
| `AppointmentEmailService::sendConfirmation()` (`lib/Service/AppointmentEmailService.php:143`) and `sendReminder()` (`:184`), send at `:427` | the send | `reminder` |
| `EmailFallbackSender::send()` (`lib/Service/EmailFallbackSender.php:96`), send at `:123`, called from `BerichtenboxService.php:338`, `:622` | the send | the category of the Berichtenbox message, default `service` |

A refused reminder is marked not sent with the reason. `BookingAdminController::sendReminder()` (`lib/Controller/BookingAdminController.php:236`) shows it. A refused fallback is logged and the Berichtenbox status stays as it is.

Not changed: `PortalMailService` (`lib/Service/Portal/PortalMailService.php:111`), category `account`, exempt. `PosBookkeepingService` (`:703`) mails an internal administrator.

## 6. The link

- **Email.** `MailTransportService` fills `{{unsubscribe_link}}` from the delivery's `unsubscribeUrl` (`lib/Service/Marketing/MailTransportService.php:264`). For a list send, pipelinq's own list link stays: it is named in older mail and in `List-Unsubscribe` headers, and its POST now writes to integriq. For a segment send, which has none today (`:233-235`), the link is integriq's. `InstanceMailerTransport::applyHeaders()` (`lib/Service/Marketing/Transport/InstanceMailerTransport.php:154`) sets the headers from integriq's material through OpenRegister's `UnsubscribeHeaders` helper.
- **SMS and WhatsApp.** The STOP keyword stays. pipelinq already handles it. The SMS footer adds integriq's `smsText` only when the provider has no inbound path.
- **Appointment and fallback mail.** integriq's link line in the body.

## 7. Contact erasure

`ClientManagementIntegration::onContactDeleted()` (`lib/Service/ClientManagementIntegration.php:65`) calls `ConsentService::deleteForContact()` (`:71`, `ConsentService.php:292`). After the cutover it no longer deletes integriq's opt-out. It dispatches `OptOutChangeRequestedEvent` with state `erase-contact` and the contact UUID. integriq clears `contact_ref` and the evidence and keeps the opt-out under the address (Ruben, 2026-10-05, decision 5). pipelinq's own history records for the contact are still deleted, as now.

## 8. Decisions (approved by Ruben 2026-10-05)

The fleet decisions are in hydra's design section 12. The ones that shape this change:

- **Fail closed.** Without integriq, every non-exempt send is refused with `authority-unavailable` and logged at warning level with the channel and the category. Account and security mail is sent.
- **Replies pass an opt-out.** An SMS or WhatsApp answer inside a conversation the contact started is sent as `reply` with the inbound message id as `inReplyTo`. Messages pipelinq starts, including business-initiated WhatsApp templates, are not replies.
- **Erasure keeps the opt-out** (section 7).
- **Headers through OpenRegister.** `InstanceMailerTransport::applyHeaders()` (`lib/Service/Marketing/Transport/InstanceMailerTransport.php:154`) delegates to OpenRegister's shared `UnsubscribeHeaders` helper (ConductionNL/openregister#4334, REQ-ERO-005). pipelinq keeps no own copy of the guarded path.
- **The short SMS link** comes from integriq when a provider has no inbound keyword path.

## 9. One open point (pipelinq only)

**Delegate `latestState()`?** The UI shows the consent state per channel (`MessagingController.php:242`). The decision event answers "may I send", not "what is the state". Recommended: answer it from the decision code, as in section 2. The alternative is a third, read-only integriq event. Ruben's 2026-10-05 answers do not cover this.
