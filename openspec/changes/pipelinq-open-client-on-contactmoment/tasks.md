## 1. Declare the notification rules

- [ ] Add an `x-openregister-notifications` block to the `contactmoment` schema in `lib/Settings/pipelinq_register.json` (schema slug `contactmoment`, currently has none).
- [ ] Add the `incomingCall` rule: `trigger.type: "created"` with `trigger.filter: { field: "channel", operator: "equals", value: "telefoon" }`, `enabled: true`.
- [ ] Add the `incomingEmail` rule: same shape with `value: "email"`.
- [ ] Set `originApp: "pipelinq"` and `channels: ["nc-notification", "web-push"]` on both rules.
- [ ] Set `recipients: [{ kind: "field", field: "agent" }, { kind: "groups", groups: ["sales"] }]` on both rules (handling agent + `sales`-group fallback so unrouted/system-created inbound interactions still reach the team; the dialect unions recipients).
- [ ] Wire the relation-resolved "Open client" primary action on both rules: `actions: [{ label: { nl: "Klant openen", en: "Open client" }, primary: true, target: { kind: "object-detail", object: { kind: "relation", field: "client" } } }]`.
- [ ] Add nl/en `subject` to both rules ("Inkomende oproep van {{client}}" / "Incoming call from {{client}}"; "Inkomende e-mail van {{client}}" / "Incoming email from {{client}}").

## 2. Verify against the contract and the register

- [ ] Verify each rule against the `notification-actions-and-web-push` contract: `web-push` channel valid, `object-detail` relation target shape exact, at most 2 `actions`, i18n labels present.
- [ ] Verify `lib/Settings/pipelinq_register.json` parses as valid JSON after the edit.
- [ ] Smoke-test the rule fires: create a `contactmoment` with `channel: "telefoon"`, a populated `client`, and an `agent`, and confirm the agent receives the notification with the "Open client" action resolving to the related Client.

## Acceptance Criteria

- The `contactmoment` schema carries an `x-openregister-notifications` block with `incomingCall` and `incomingEmail` rules.
- Both rules notify the `agent` and the `sales`-group fallback, declare `originApp: "pipelinq"`, deliver on `nc-notification` + `web-push`, and expose a single primary "Open client" action targeting the `client` relation via `object-detail`.
- Subjects and action labels are present in nl and en.
- No PHP/Vue is added; the change is the schema-register JSON patch only (ADR-031 declarative).
- The register JSON remains valid and the change validates `--strict`.

## Quality reminders

- Use only SAFE placeholders in any example (nil UUID `00000000-0000-0000-0000-000000000000`); no real client UUIDs or secrets.
- Do not modify the existing `lead`/`task` notification blocks.
- Fix any pre-existing JSON/quality issue encountered in the touched region of the register rather than leaving it.
- Keep the addition purely additive — back-compatible, no other schema touched.
