# SLA Engine and Escalation — Outbound Messaging Dispatch Delta

**Spec refs**: `outbound-messaging` (this change), OR `MessageDispatchProvider` (`../openregister` origin/development, `lib/Service/Integration/Providers/MessageDispatchProvider.php`), ADR-022 (apps consume OR abstractions)
**Standards**: Meta WhatsApp business-messaging policy (template-gated business-initiated messages), VNG Klantinteracties (contactmoment audit)

## MODIFIED Requirements

### Requirement: Escalation chain execution

When `consumedPercentage` crosses an escalation `triggerAt` threshold, the engine MUST notify the configured actor on the configured channel exactly once per level per object, and write an `sla_breach_event`. Channels `sms` and `whatsapp` MUST dispatch through the channel adapters (`SmsAdapter` / `WhatsAppAdapter`, transported via OpenRegister's `MessageDispatchProvider` leaf) and are supported for `notify: customer` — the breached object's linked client/contact is the recipient. For other notify roles, `sms`/`whatsapp` steps MUST record an `unsupported:{channel}:{role}` marker in `notifiedActors` without dispatching. Channels `email` and `webhook` remain delegated to their own capabilities and MUST record a `deferred:{channel}:{role}` marker until those land. Adapter dispatch MUST be resolved lazily (container), MUST never let a Throwable escape the sweep, and every outcome MUST be auditable through the `notifiedActors` marker vocabulary (`sent` = actor identifier, `consent-missing:`, `template-missing:`, `unsupported:`, `deferred:`, `failed:`, `unresolved:`).

**Feature tier**: V1 (sms/whatsapp escalation dispatch); MVP (notification channel, unchanged)

#### Scenario: Email sent to team-lead at 80% threshold

- **GIVEN** an SLA with escalation step: `triggerAt: 0.8`, `notify: team-lead`, `channel: email`
- **WHEN** a target's `consumedPercentage` reaches 0.80 or higher
- **THEN** an email MUST be sent to the team-lead (resolved via user lookup)
- AND `sla_breach_event` MUST be created with `escalationLevel: 1`, `breachedAt: now()`, `consumedPercentage: 0.80`, `notifiedActors: [team-lead-email]`

#### Scenario: Multiple escalation steps fire in order

- **GIVEN** an SLA with two escalation steps: step 1 at 0.8 (email), step 2 at 1.0 (notification)
- **WHEN** consumption reaches 80%
- **THEN** step 1 fires (email sent, event created with `escalationLevel: 1`)
- **WHEN** consumption later reaches 100%
- **THEN** step 2 fires (notification sent, new event created with `escalationLevel: 2`)
- AND step 1 MUST NOT fire again (idempotent per level)

#### Scenario: No escalation if resolved before threshold

- **GIVEN** an SLA with a target due in 4 hours
- **WHEN** the request is resolved within 2 hours (50% consumption)
- **THEN** no escalation MUST fire
- AND `slaStatus.targets[*].status` MUST be set to `met`
- AND `slaStatus.targets[*].metAt` MUST be populated with resolution timestamp

#### Scenario: Escalation to customer via WhatsApp

- **GIVEN** an SLA with escalation: `triggerAt: 1.5`, `notify: customer`, `channel: whatsapp`, `templateId: <approved messageTemplate>`
- AND the breached object links a client/contact with a phone number and an `opted-in` WhatsApp consent record
- **WHEN** breach percentage reaches 150%
- **THEN** the engine MUST load the linked contact and call `WhatsAppAdapter::send()` with the step's `templateId`
- AND the adapter MUST dispatch through the OpenRegister `MessageDispatchProvider` leaf and persist the outbound `message` row
- AND `sla_breach_event.notifiedActors` MUST include the contact identifier (never `deferred:`)

#### Scenario: Escalation to customer via SMS

- **GIVEN** an SLA with escalation: `triggerAt: 1.0`, `notify: customer`, `channel: sms`
- AND the breached object links a contact with a phone number and no `opted-out` SMS consent record
- **WHEN** the threshold is crossed
- **THEN** the engine MUST call `SmsAdapter::send()` with the policy's rendered breach message
- AND the outbound `message` row MUST be persisted and `notifiedActors` MUST include the contact identifier

#### Scenario: WhatsApp escalation without consent fails closed

- **GIVEN** an SLA whatsapp escalation step for `notify: customer`
- AND the linked contact has no `opted-in` WhatsApp consent record
- **WHEN** the threshold is crossed
- **THEN** no message MUST be dispatched
- AND `notifiedActors` MUST include `consent-missing:whatsapp`
- AND the breach event MUST still be written (audit is never skipped)

#### Scenario: WhatsApp escalation without a template fails closed

- **GIVEN** an SLA whatsapp escalation step with no `templateId` and no open 24h session window for the contact
- **WHEN** the threshold is crossed
- **THEN** no message MUST be dispatched
- AND `notifiedActors` MUST include `template-missing:whatsapp`

#### Scenario: SMS escalation to a non-customer role is unsupported

- **GIVEN** an SLA with escalation: `notify: team-lead`, `channel: sms`
- **WHEN** the threshold is crossed
- **THEN** no message MUST be dispatched
- AND `notifiedActors` MUST include `unsupported:sms:team-lead`

#### Scenario: Webhook escalation dispatch

- **GIVEN** an SLA with escalation: `notify: webhook`, `channel: webhook`
- **WHEN** threshold is crossed
- **THEN** the engine MUST record `deferred:webhook:webhook` in `notifiedActors` (webhook dispatch is delegated to the OR `WebhookService` integration owned by its own capability; when that lands, `WebhookService::dispatchEvent()` MUST be called with the `sla_breach_event` as CloudEvents payload, event type `nl.conduction.sla.breach`, webhook URL configurable per policy)

`@e2e exclude` backend escalation dispatch — SLA sweeps have no UI trigger; asserted by PHPUnit (`SlaEngineService` dispatch tests with adapter mocks + marker-vocabulary assertions) and the Newman/mock-source contract ring per `outbound-messaging` REQ-OM-006.
