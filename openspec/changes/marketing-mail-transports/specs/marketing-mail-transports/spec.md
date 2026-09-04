## Purpose

Defines per-tenant email sending routes for the marketing blast engine: the
instance mail server as the default, a sender's own Nextcloud Mail account,
or a bulk provider reached through OpenConnector, plus a deliverability
panel showing SPF/DKIM/DMARC status per sender domain.

## ADDED Requirements

### Requirement: A tenant sends with zero configuration

A fresh Pipelinq install SHALL carry one `mailTransport` of `kind = instance`
marked `default = true`, so a blast can be sent through the Nextcloud
instance mail server without any admin configuration.

#### Scenario: Unconfigured tenant sends through the instance mailer

- **WHEN** a blast names no `transportId` and no admin has created another
  transport
- **THEN** the send resolves to the seeded `kind = instance` transport and
  dispatches through the Nextcloud instance mail server

### Requirement: A blast selects its transport, falling back to the default

Each blast SHALL resolve exactly one `mailTransport` at send time: the one
named by `blast.transportId` when set and active, otherwise the transport
marked `default = true`.

#### Scenario: Blast names an explicit transport

- **WHEN** `blast.transportId` names an active `mailTransport`
- **THEN** the send resolves to that transport

#### Scenario: Blast names no transport

- **WHEN** `blast.transportId` is empty
- **THEN** the send resolves to the `mailTransport` marked `default = true`

#### Scenario: Blast names a transport that no longer exists or is inactive

- **WHEN** `blast.transportId` names a transport that has been deleted or set
  `active = false`
- **THEN** the send resolves to the default transport instead of failing the
  delivery

### Requirement: A Mail-account transport sends through the sender's own account

A `kind = mailAccount` transport SHALL send through the named Nextcloud Mail
account when the Mail app is installed, and SHALL degrade to a recorded,
non-fatal failure when it is not.

#### Scenario: Mail account transport sends a delivery

- **GIVEN** a `mailAccount` transport naming an existing Mail account
- **WHEN** a delivery is dispatched through it
- **THEN** the message is sent through that Mail account's outbox and the
  delivery is recorded as sent

#### Scenario: Mail app is not installed

- **GIVEN** a `mailAccount` transport, and the Mail app is not installed on
  the instance
- **WHEN** a delivery is dispatched through it
- **THEN** the delivery is recorded as failed with a logged reason, and no
  exception reaches the caller

### Requirement: A provider transport never carries a credential

A `kind = provider` transport SHALL reference an OpenConnector source by
`connectorSourceId` and SHALL NOT store a provider API key, secret, or other
credential on the `mailTransport` object itself.

#### Scenario: Provider transport resolves credentials through OpenConnector

- **GIVEN** a `provider` transport with a `connectorSourceId`
- **WHEN** a delivery is dispatched through it
- **THEN** the send is delegated to the named OpenConnector source, and no
  provider credential is read from or written to the `mailTransport` object

#### Scenario: Existing SendGrid sends keep working

- **GIVEN** a blast configured exactly as it was before this change
  (`connectorSourceId` set, no `transportId`)
- **WHEN** the blast is dispatched
- **THEN** the request sent to the SendGrid OpenConnector source is
  unchanged from before this change

### Requirement: A transport enforces its own daily send limit

A `mailTransport` with `dailyLimit > 0` SHALL refuse further sends once
`sentToday` reaches `dailyLimit`, resetting the counter at the start of each
calendar day, independently of any per-OpenConnector-source rate limit.

#### Scenario: Transport under its daily limit sends normally

- **GIVEN** a transport with `dailyLimit = 100` and `sentToday = 40`
- **WHEN** a delivery is dispatched through it
- **THEN** the send proceeds and `sentToday` becomes 41

#### Scenario: Transport at its daily limit refuses further sends

- **GIVEN** a transport with `dailyLimit = 100` and `sentToday = 100`
- **WHEN** a delivery is dispatched through it
- **THEN** the delivery is not sent, is recorded as failed with a
  limit-reached reason, and `sentToday` is not incremented further

#### Scenario: Daily limit resets on a new calendar day

- **GIVEN** a transport with `sentToday = 100` and `dailyLimitResetAt` set to
  yesterday
- **WHEN** a delivery is dispatched through it today
- **THEN** `sentToday` resets to 0 before the send is evaluated against
  `dailyLimit`

### Requirement: Header injection on the instance mailer degrades soft

When a rendered mail carries extra headers, the instance-mailer transport
SHALL set them via the guarded private-API path when available, and SHALL
send the message without them, logging the omission, when it is not.

#### Scenario: Header path available

- **GIVEN** the runtime `IMailer` message implementation exposes the guarded
  header-setting method
- **WHEN** a delivery carrying extra headers is sent through the instance
  mailer transport
- **THEN** those headers are present on the sent message

#### Scenario: Header path unavailable

- **GIVEN** the runtime `IMailer` message implementation does not expose the
  guarded header-setting method
- **WHEN** a delivery carrying extra headers is sent through the instance
  mailer transport
- **THEN** the message is still sent, without the extra headers, and the
  omission is logged

### Requirement: The wizard offers a transport step

The blast-creation wizard SHALL offer a step for choosing the transport,
listing only `active` transports, defaulting the selection to the transport
marked `default = true`.

#### Scenario: Wizard lists active transports

- **WHEN** a user reaches the transport step of the blast wizard
- **THEN** every `active = true` `mailTransport` is offered, and the one
  marked `default = true` is pre-selected

### Requirement: The deliverability panel shows SPF, DKIM and DMARC status per sender domain

An admin settings section SHALL list every `mailTransport` with its active
and default state, and, per distinct `senderDomain`, a plain-language SPF,
DKIM and DMARC verdict from a cached DNS lookup.

#### Scenario: Panel lists transports with their state

- **WHEN** an admin opens the deliverability panel
- **THEN** every `mailTransport` is listed with its `active` and `default`
  state, each togglable from the panel

#### Scenario: Panel shows a DMARC verdict

- **GIVEN** a sender domain with no DMARC record
- **WHEN** the panel checks that domain
- **THEN** it shows a plain-language verdict such as "DMARC missing: bulk
  senders to Gmail are rejected without it"

#### Scenario: DNS lookup failure degrades soft

- **GIVEN** a sender domain whose DNS lookup fails or times out
- **WHEN** the panel checks that domain
- **THEN** it shows an "unknown" verdict rather than an error, and the panel
  itself still renders

#### Scenario: DNS results are cached

- **GIVEN** a sender domain checked within the last 24 hours
- **WHEN** the panel is reopened
- **THEN** the cached verdict is shown without a new DNS lookup, until the
  admin requests a refresh
