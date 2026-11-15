# Proposal: customer-satisfaction-closed-loop

## Problem

The KTO/NPS V1 capability (archived change `2026-03-22-customer-satisfaction`) shipped survey CRUD, a tokenized public response endpoint, on-the-fly NPS calculation, analytics views, and dashboard KPI/trend widgets. Its design explicitly deferred distribution automation ("No email distribution — future scope"). The result is a measurement engine that nobody feeds and nobody acts on:

1. **No automated invitation dispatch** — Surveys only collect responses if an operator manually copies the public URL into an email. Best-in-class CRMs (HubSpot Service feedback, Zendesk CSAT, Medallia) send the survey automatically the moment an interaction completes. Without this, response volume stays near zero and the NPS widgets render noise.

2. **No per-respondent invitation tracking** — The V1 token is per-survey, not per-invitation. There is no record of *who* was invited and *when*, so response rate — the most basic survey health metric, promised in `docs/Features/customer-satisfaction.md` — cannot be computed, and duplicate submissions from the same recipient cannot be distinguished from distinct respondents.

3. **No survey-fatigue protection** — A KCC handling 5 contactmomenten per citizen per week would, with naive automation, send 5 surveys per week to the same person. Over-surveying is the fastest way to destroy both response rates and customer goodwill; every serious CSAT product throttles per-contact.

4. **The loop is open: detractors disappear** — An NPS 0–6 response is the single highest-value signal a CRM can produce, and today it lands silently in an analytics view nobody watches. Closed-loop feedback (alert the account owner, create a follow-up task, track resolution) is the entire point of NPS as practiced by Bain's methodology and every major CRM.

5. **Satisfaction is invisible in the 360° client view** — `customer-360` aggregates contactmomenten, requests, and leads per client, but per-client satisfaction (promised in the feature doc: "Aggregate satisfaction per client for 360-degree client view") was never delivered. An account manager opening a client cannot see "this client's NPS is 2 and falling."

6. **Stale documentation** — `docs/Features/customer-satisfaction.md` still says "Status: Planned" although V1 is implemented and archived, and `docs/Features/terugbel-taakbeheer.md` says "Planned" although the `callback-management` capability covers it. Both misrepresent the product (flagged by the 2026-06-11 feature re-evaluation).

## Solution

Close the feedback loop on top of the existing V1 engine:

1. **`surveyInvitation` schema + dispatch rules** — A per-recipient invitation object (unique response token, surveyRef, contact reference via the existing `contact` schema's `contactsUid`, linked entity, channel, sentAt, respondedAt, expiresAt). Admin-configurable dispatch rules: *when* (contactmoment closed, request resolved, klacht afgehandeld), *which survey*, *which channel* (email via the existing automation email action; WhatsApp/SMS via the channel adapters when present), and *lead time/delay*.

2. **Event-driven automated dispatch** — Listen to the same OR object events that `crm-workflow-automation` consumes. When a tracked interaction completes and a dispatch rule matches, generate a `surveyInvitation` with a unique token and send the personalized link through the configured channel. The public endpoint accepts invitation tokens, marks the invitation responded, and links the response to the invitation's entity and contact.

3. **Survey-fatigue throttling + opt-out** — A per-contact cooldown window (default 30 days, admin-configurable) suppresses dispatch when the contact was invited recently — for *any* survey. A survey opt-out flag on the response form and on the contact is honored permanently. Suppressed dispatches are recorded with a reason for auditability.

4. **Response-rate analytics** — Invitations vs. responses per survey, per channel, per period, surfaced in the existing SurveyAnalytics view.

5. **Detractor closed-loop follow-up** — When a response scores NPS ≤ 6 (or rating ≤ 2 on a 1–5 scale), create a My Work follow-up task assigned to the linked client's owner (fallback: configured default assignee) and emit a notification through the OpenRegister x-openregister-notifications dialect (ADR-031). The follow-up task references the response so the operator sees verbatims before calling back.

6. **Customer 360 satisfaction panel** — Per-client NPS, average rating, response count, trend direction, and the most recent verbatims, computed from responses linked (directly or via their invitation's entity) to the client.

7. **Documentation conformance** — Update `docs/Features/customer-satisfaction.md` to reflect implemented V1 + this change's scope, and re-point `docs/Features/terugbel-taakbeheer.md` at the `callback-management` capability.

## Scope

New schema in `pipelinq_register.json`:
- `surveyInvitation` — token, surveyRef, contactRef (contactsUid), linkedEntityType, linkedEntityId, channel, status, sentAt, respondedAt, responseRef, expiresAt, suppressionReason

Extensions to existing schemas:
- `surveyResponse` gains `invitationRef`
- `contact` gains `surveyOptOut` (boolean)

Backend services:
- `SurveyDispatchService` — rule matching, invitation creation, throttle/opt-out enforcement, channel hand-off
- `DetractorFollowUpService` — score classification, task creation, notification emission
- `SatisfactionAggregationService` — per-client and response-rate aggregates
- Event listener wiring on interaction-completion events (reuses ObjectEventDispatcher stream)

Frontend:
- Dispatch-rule management section in survey settings (per-survey trigger/channel/delay/cooldown)
- Response-rate block in SurveyAnalytics
- Satisfaction panel in the customer-360 client view
- Opt-out checkbox on the public survey form

Notifications: schema-rule based via the x-openregister-notifications dialect in `lib/Settings/pipelinq_register.json` (ADR-031) — no imperative dispatch.

Seed data: 1 example dispatch rule (contactmoment closed → KTO survey, email, 30-day cooldown).

**Depends on:** `customer-satisfaction` V1 (archived 2026-03-22), `crm-workflow-automation` (event stream + email action), `contacts-sync` (contact/contactsUid), `customer-360`, `my-work` (follow-up tasks), OpenRegister notifications (ADR-031). Optional: `whatsapp-sms-channel-adapter` (extra channels when installed).

## Out of Scope

- New survey question types or survey-builder changes — V1 builder is unchanged
- A/B testing of survey content
- External survey tool integration (Typeform, Google Forms)
- Marketing-blast-style bulk survey campaigns — that is `marketing-segmentation-and-blast` territory; this change only dispatches transactional, interaction-triggered invitations
- A new customer/contact schema — recipients are always existing `contact` objects synced with the NC addressbook (ContactSyncService); never an app-local customer schema
- SLA on detractor follow-up resolution — escalation belongs to `sla-engine-and-escalation`

## Success Criteria

- An admin configures "when a contactmoment is closed on channel phone, send the KTO survey by email after 1 hour, cooldown 30 days" without touching code
- Closing a contactmoment for a contact with an email address produces a `surveyInvitation` with a unique token and an outbound email containing the personalized link
- Closing five contactmomenten for the same contact within the cooldown window produces exactly one invitation; the four suppressed dispatches are recorded with reason `cooldown`
- A contact who ticks "don't ask me again" never receives another invitation, across all surveys
- SurveyAnalytics shows invitations sent, responses received, and response rate per channel and period
- An NPS-3 response creates a My Work task for the client owner within one cron run and the owner receives a Nextcloud notification via the OR notification engine
- Opening a client in customer-360 shows the client's NPS, average rating, response count, and last verbatim
- `docs/Features/customer-satisfaction.md` no longer claims "Planned"; `terugbel-taakbeheer.md` points at callback-management
