## 1. Add the notification body to the existing rules

- [x] Add an i18n `message` (BODY) field to the `incomingCall` rule in the `contactmoment` schema (`lib/Settings/pipelinq_register.json`): `{ nl: "{{client}} is een contact in Pipelinq. Openen in Nextcloud?", en: "{{client}} is a contact in Pipelinq. Open it in Nextcloud?" }`.
- [x] Add an i18n `message` field to the `incomingEmail` rule: `{ nl: "{{client}} is een contact in Pipelinq. E-mail openen in Nextcloud?", en: "{{client}} is a contact in Pipelinq. Open the email in Nextcloud?" }`.
- [x] Leave the existing `subject` (TITLE), `originApp`, `channels`, `recipients`, and `actions` of both rules unchanged.

## 2. Add the incomingChat rule

- [x] Add an `incomingChat` rule mirroring `incomingEmail`: `trigger.type: "created"` with `trigger.filter: { field: "channel", operator: "equals", value: "chat" }`, `enabled: true`.
- [x] Set `originApp: "pipelinq"` and `channels: ["nc-notification", "web-push"]`.
- [x] Set `recipients: [{ kind: "field", field: "agent" }, { kind: "groups", groups: ["sales"] }]` (handling agent + `sales`-group fallback).
- [x] Set `subject`: `{ nl: "Inkomend chatbericht van {{client}}", en: "Incoming chat from {{client}}" }`.
- [x] Set `message`: `{ nl: "{{client}} is een contact in Pipelinq. Gesprek openen in Nextcloud?", en: "{{client}} is a contact in Pipelinq. Open the conversation in Nextcloud?" }`.
- [x] Wire the relation-resolved "Open client" primary action: `actions: [{ label: { nl: "Klant openen", en: "Open client" }, primary: true, target: { kind: "object-detail", object: { kind: "relation", field: "client" } } }]`.

## 3. Verify against the register and the engine

- [x] Verify `lib/Settings/pipelinq_register.json` parses as valid JSON after the edits.
- [x] Verify `chat` is a member of the `contactmoment` `channel` enum (so the `incomingChat` filter can match).
- [x] Verify each rule still satisfies the dialect: `web-push` channel valid, `object-detail` relation target shape exact, at most 2 `actions`, i18n nl/en on every `subject`, `message`, and action label.
- [x] Smoke-test: re-import the register, create a `contactmoment` (`channel: telefoon`, `agent: admin`, populated `client`), and confirm the stored notification has DISTINCT title (`_text`) and body (`_message`) in `oc_notifications.subject_parameters`.

## Acceptance Criteria

- `incomingCall` and `incomingEmail` each carry an i18n `message` (BODY) distinct from `subject` (TITLE); their other fields are unchanged.
- A new `incomingChat` rule fires on `created` + `channel == "chat"`, with the same `originApp`, channels, recipients, and "Open client" relation action as `incomingEmail`, and chat-appropriate `subject` + `message`.
- Subjects, messages, and action labels are present in nl and en.
- No PHP/Vue is added; the change is the schema-register JSON patch only (ADR-031 declarative).
- The register JSON remains valid and the change validates `--strict`.

## Quality reminders

- Use only SAFE placeholders in any example (nil UUID `00000000-0000-0000-0000-000000000000`); no real client UUIDs or secrets.
- Do not modify the existing `subject` strings, recipients, channels, `originApp`, or actions of `incomingCall`/`incomingEmail`, and do not touch the `lead`/`task` notification blocks.
- Fix any pre-existing JSON/quality issue encountered in the touched region of the register rather than leaving it.
- Keep the addition purely additive — back-compatible, no other schema touched.
