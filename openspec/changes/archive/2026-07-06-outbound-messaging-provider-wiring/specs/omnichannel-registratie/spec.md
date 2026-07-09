# Omnichannel Registratie — Outbound Send Audit Delta

**Spec refs**: `outbound-messaging` (this change, REQ-OM-006), VNG Klantinteracties (`Contactmoment`, `Kanaal`), ADR-037 (register fragment import via Repair)
**Standards**: Schema.org `CommunicateAction`, VNG Klantinteracties

## ADDED Requirements

### Requirement: Outbound messages registered as contactmomenten

Every successful outbound WhatsApp or SMS send (agent composer or SLA escalation) MUST automatically produce a `contactmoment` with the unified core structure (timestamp, acting agent or `system:sla-engine`, client/contact reference, subject, summary) and the channel envelope: WhatsApp stored as `channel: "chat"` with `metadata: {platform: "whatsapp", direction: "outbound", messageId, conversationId}` (consistent with the existing chat-channel convention), SMS stored under a new `channel: "sms"` enum value with the same metadata shape. The `contactmoment.channel` enum (`lib/Settings/pipelinq_register.json`, currently `telefoon|email|balie|chat|social|brief`) MUST be extended with `sms`, channel statistics and rapportage channel labels MUST include the new value, and existing contactmomenten MUST be unaffected (additive enum change imported via the ADR-037 Repair step).

**Feature tier**: V1

#### Scenario: Outbound WhatsApp appears in the client's contactmoment timeline

- **GIVEN** an agent sends a WhatsApp message from a client detail page
- **WHEN** the send succeeds
- **THEN** a `contactmoment` MUST exist with `channel: "chat"`, `metadata.platform: "whatsapp"`, `metadata.direction: "outbound"`, and the client reference
- AND it MUST appear in the client's contactmomenten list without manual registration

#### Scenario: SMS channel value is reportable

- **GIVEN** outbound SMS contactmomenten exist
- **WHEN** channel statistics (rapportage) are computed
- **THEN** the `sms` channel MUST appear as its own bucket alongside the existing channels

#### Scenario: Additive enum migration is safe

- **GIVEN** an instance with existing contactmomenten
- **WHEN** the register fragment with the extended enum is re-imported via the Repair step
- **THEN** all existing rows MUST remain valid and readable and no other `contactmoment` property MUST change
