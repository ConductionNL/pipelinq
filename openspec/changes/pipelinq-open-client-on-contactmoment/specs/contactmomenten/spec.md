## ADDED Requirements

### Requirement: Inbound Contactmoment notifies the agent with an Open-client action

When an inbound Contactmoment is created on the `telefoon` or `email` channel, the system SHALL notify the handling agent — with the `sales` group added as a fallback recipient so an unrouted inbound interaction (empty `agent`) still reaches the team — via a declarative `x-openregister-notifications` rule on the `contactmoment` schema, and the notification SHALL carry a primary action button whose `target` is an `object-detail` resolved server-side through the `client` relation (the "Open client" button). The rules SHALL be expressed purely in the schema-register JSON (`kind: config`, ADR-031) with no service class, and SHALL use the dialect features defined by the `notification-actions-and-web-push` contract: `actions[]` (capped at the contract's maximum of 2), `originApp`, the relation-resolved `object-detail` target kind, and the `web-push` channel.

**Standards**: VNG Klantinteracties (`Contactmoment` → `KlantContactmoment` → `Klant`), Schema.org (`CommunicateAction`)
**Feature tier**: V1 (notification on inbound interaction)

#### Scenario: Incoming call notifies the agent with an Open-client button

- **WHEN** a `contactmoment` object is created with `channel == "telefoon"` and a populated `client` relation and `agent` field
- **THEN** the engine dispatches a notification to the `agent` user and the `sales` group on the `nc-notification` and `web-push` channels, carrying pipelinq's identity (`originApp: "pipelinq"`) and a single primary action labelled "Open client" / "Klant openen" whose `object-detail` target resolves to the related Client object server-side at dispatch

#### Scenario: Incoming email notifies the agent with the same Open-client action

- **WHEN** a `contactmoment` object is created with `channel == "email"` and a populated `client` relation and `agent` field
- **THEN** the engine dispatches the analogous `incomingEmail` notification to the `agent` and the `sales` group with the same "Open client" relation-resolved `object-detail` action

#### Scenario: Unrouted inbound interaction still reaches the sales team

- **WHEN** an inbound `telefoon`/`email` `contactmoment` is created with no `agent` set (e.g. created by a CTI or email-to-lead integration before routing)
- **THEN** the `field: agent` recipient resolves to nobody but the `groups: sales` fallback recipient still receives the notification, so the inbound interaction is not silently dropped

#### Scenario: Contactmoment on a non-inbound channel does not fire

- **WHEN** a `contactmoment` object is created with `channel` set to a value other than `telefoon` or `email` (e.g. `balie`, `chat`, `social`, `brief`)
- **THEN** neither the `incomingCall` nor the `incomingEmail` rule matches and no notification is dispatched by these rules

#### Scenario: Notification delivered while no Nextcloud tab is open

- **WHEN** the handling `agent` has an active web-push subscription but no open Nextcloud tab at the moment an inbound `telefoon`/`email` Contactmoment is created
- **THEN** the `web-push` channel delivers the encrypted background notification and a click on the "Open client" action opens the resolved Client deeplink

#### Scenario: Rule honours the contract action cap

- **WHEN** the `contactmoment` notification block is saved
- **THEN** each rule declares at most 2 `actions` (the Web Notification API desktop limit enforced by the contract), with i18n nl/en labels on every action and an nl/en `subject`
