## ADDED Requirements

### Requirement: Inbound Contactmoment notification carries a distinct body and covers the chat channel

The inbound-Contactmoment notification rules on the `contactmoment` schema SHALL declare a distinct notification body (`message`) in addition to the title (`subject`), and SHALL cover the `chat` inbound channel in addition to `telefoon` and `email`. The `subject` SHALL state the event (the notification TITLE) and the `message` SHALL state the context plus the open-in-Nextcloud call-to-action (the notification BODY), both as i18n nl/en strings. The rules SHALL be expressed purely in the schema-register JSON (`kind: config`, ADR-031) and SHALL use the `message` field defined by the `openregister-notification-body` change, with the `incomingChat` rule mirroring `incomingEmail` (same `originApp: "pipelinq"`, `["nc-notification", "web-push"]` channels, agent + `sales`-group recipients, and the relation-resolved `object-detail` "Open client" action).

**Standards**: VNG Klantinteracties (`Contactmoment` → `KlantContactmoment` → `Klant`), Schema.org (`CommunicateAction`)
**Feature tier**: V1 (notification on inbound interaction)

#### Scenario: Notification title and body are distinct

- **WHEN** an inbound `telefoon` `contactmoment` is created and the `incomingCall` rule dispatches a notification
- **THEN** the notification title is rendered from `subject` ("Incoming call from {{client}}") and the notification body is rendered from `message` ("{{client}} is a contact in Pipelinq. Open it in Nextcloud?"), so the two lines are not identical

#### Scenario: Incoming email notification has its own body wording

- **WHEN** an inbound `email` `contactmoment` is created
- **THEN** the `incomingEmail` notification title is "Incoming email from {{client}}" and its body is the email-specific `message` ("{{client}} is a contact in Pipelinq. Open the email in Nextcloud?")

#### Scenario: Incoming chat notifies the agent with an Open-client button

- **WHEN** a `contactmoment` object is created with `channel == "chat"` and a populated `client` relation and `agent` field
- **THEN** the engine dispatches the `incomingChat` notification to the `agent` user and the `sales` group on the `nc-notification` and `web-push` channels, with `originApp: "pipelinq"`, the title "Incoming chat from {{client}}", the body "{{client}} is a contact in Pipelinq. Open the conversation in Nextcloud?", and a single primary "Open client" / "Klant openen" action whose `object-detail` target resolves to the related Client object server-side

#### Scenario: Body falls back to the title when no message is declared

- **WHEN** a rule (e.g. another schema's rule) without a `message` field dispatches a notification under the `openregister-notification-body` engine
- **THEN** the body falls back to the `subject`, so rules that have not adopted `message` keep working unchanged

#### Scenario: Unrouted inbound chat still reaches the sales team

- **WHEN** an inbound `chat` `contactmoment` is created with no `agent` set (e.g. by a chat-widget integration before routing)
- **THEN** the `field: agent` recipient resolves to nobody but the `groups: sales` fallback recipient still receives the `incomingChat` notification, so the inbound chat is not silently dropped

#### Scenario: i18n present on every title, body, and action label

- **WHEN** the `contactmoment` notification block is saved
- **THEN** each of `incomingCall`, `incomingEmail`, and `incomingChat` declares an nl/en `subject`, an nl/en `message`, and at most 2 `actions` whose labels carry nl/en text
