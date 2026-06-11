# Customer Satisfaction — Closed-Loop Delta

**Spec refs**: archived change `2026-03-22-customer-satisfaction` (V1 engine), ADR-000 (data model), ADR-031 (x-openregister-notifications dialect), `crm-workflow-automation`, `contacts-sync`, `my-work`
**Standards**: GEMMA Klanttevredenheidcomponent, GEMMA Klantfeedbackcomponent, Bain NPS closed-loop methodology, TEC CRM §4.1/§4.3

## ADDED Requirements

### Requirement: Survey Invitation Schema Registration

The system MUST register a `surveyInvitation` schema in the pipelinq register carrying a unique per-invitation response token, survey reference, recipient contact reference (the existing `contact` schema's `contactsUid` — never an app-local customer record), triggering entity linkage, channel, lifecycle status (`scheduled`, `sent`, `responded`, `expired`, `suppressed`, `failed`), timestamps, and suppression reason. The `surveyResponse` schema MUST gain an optional `invitationRef` and the `contact` schema MUST gain a `surveyOptOut` boolean.

**Feature tier**: MVP

#### Scenario: Schema registration

- WHEN the repair step runs
- THEN the `surveyInvitation` schema MUST exist in the pipelinq register with all listed properties
- AND `surveyResponse.invitationRef` and `contact.surveyOptOut` MUST be present on the existing schemas

---

### Requirement: Configurable Survey Dispatch Rules

Admins MUST be able to configure dispatch rules — trigger (entity type + terminal status, optional channel filter), target survey, delivery channel, send delay, per-contact cooldown days, and token expiry days — from the survey settings UI, without code changes.

**Feature tier**: MVP

#### Scenario: Create a dispatch rule

- GIVEN an admin in the survey settings view
- WHEN they create a rule "contactmoment closed → KTO survey, email, delay 60 minutes, cooldown 30 days"
- THEN the rule MUST be persisted in app configuration and listed as enabled

#### Scenario: Disabled rule is inert

- GIVEN a dispatch rule that is disabled
- WHEN a matching interaction completes
- THEN no `surveyInvitation` MUST be created for that rule

---

### Requirement: Automated Invitation Dispatch on Interaction Completion

The system MUST create a `surveyInvitation` with a unique token when a tracked interaction (contactmoment, request, complaint) reaches a terminal status matching an enabled dispatch rule, and MUST deliver the personalized response link through the configured channel after the rule's delay. Channel delivery failure MUST mark the invitation `failed` and MUST NOT block or roll back the triggering interaction's save.

**Feature tier**: MVP

#### Scenario: Contactmoment closure triggers an email invitation

- GIVEN an enabled rule "contactmoment closed → KTO survey via email, delay 0"
- AND a contactmoment linked to a contact with an email address
- WHEN the contactmoment status is set to closed
- THEN a `surveyInvitation` MUST be created with a unique token, `channel = email`, and the contact's `contactsUid`
- AND on the next dispatch run an email containing the link `/apps/pipelinq/survey/i/{token}` MUST be sent
- AND the invitation status MUST become `sent` with `sentAt` populated

#### Scenario: Delivery failure does not break the interaction

- GIVEN a matching rule whose channel hand-off throws
- WHEN the interaction completes
- THEN the interaction save MUST succeed unaffected
- AND the invitation MUST be persisted with status `failed`

---

### Requirement: Tokenized Invitation Response Collection

The public survey endpoint MUST accept per-invitation tokens: render the survey for a `sent`, unexpired invitation; on submission create a `surveyResponse` carrying `invitationRef` and the invitation's entity and contact linkage; and flip the invitation to `responded`. Responded or expired tokens MUST be rejected for further submissions. The V1 per-survey token path MUST keep working unchanged.

**Feature tier**: MVP

#### Scenario: Submit via invitation link

- GIVEN a `sent` invitation with a valid token
- WHEN the respondent opens the link and submits required answers
- THEN a `surveyResponse` MUST be created with `invitationRef` set and linked to the invitation's entity
- AND the invitation MUST have `status = responded`, `respondedAt` and `responseRef` populated

#### Scenario: Single use enforced

- GIVEN an invitation with `status = responded`
- WHEN its token is used again
- THEN the submission MUST be rejected and no second `surveyResponse` MUST be created

#### Scenario: Expired token

- GIVEN an invitation whose `expiresAt` is in the past
- WHEN the link is opened
- THEN a "survey closed" page MUST be shown instead of the form

---

### Requirement: Survey-Fatigue Throttling and Opt-Out

The system MUST suppress dispatch when the contact was sent any survey invitation within the matching rule's cooldown window, or when the contact's `surveyOptOut` is true; suppressed dispatches MUST be persisted as invitations with `status = suppressed` and a machine-readable `suppressionReason`. The public survey form MUST offer an opt-out control that sets `surveyOptOut` on the contact.

**Feature tier**: MVP

#### Scenario: Cooldown suppression across surveys

- GIVEN a contact who received any survey invitation 5 days ago
- AND a matching rule with `cooldownDays = 30`
- WHEN another tracked interaction for that contact completes
- THEN no message MUST be sent
- AND an invitation MUST be persisted with `status = suppressed` and `suppressionReason = cooldown`

#### Scenario: Permanent opt-out

- GIVEN a contact with `surveyOptOut = true`
- WHEN any dispatch rule matches an interaction for that contact
- THEN the invitation MUST be suppressed with reason `opt-out`

#### Scenario: Respondent opts out from the public form

- GIVEN a respondent on the public survey form
- WHEN they tick the opt-out control and submit
- THEN the linked contact's `surveyOptOut` MUST be set to true

---

### Requirement: Response-Rate Analytics

The survey analytics view MUST report invitations sent, responses received, and response rate per survey, per channel, and per period, computed from `surveyInvitation` objects. Suppressed and failed invitations MUST be excluded from the response-rate denominator but visible as separate counts.

**Feature tier**: MVP

#### Scenario: Response rate computed from invitations

- GIVEN a survey with 40 invitations of status `sent`, 10 `responded`, 5 `suppressed`, 1 `failed`
- WHEN the analytics view loads
- THEN the response rate MUST display 20% (10 of 50 delivered)
- AND suppressed (5) and failed (1) MUST be shown as separate counts, outside the denominator

---

### Requirement: Detractor Closed-Loop Follow-Up

When a `surveyResponse` contains an NPS answer of 6 or lower, or a 1–5 rating answer at or below the configured threshold (default 2), the system MUST create a My Work follow-up task assigned to the linked client's owner (fallback: a configured default assignee) referencing the response, and a notification MUST be emitted via the x-openregister-notifications schema-rule dialect (ADR-031) — never via imperative dispatch in app code.

**Feature tier**: MVP

#### Scenario: Detractor response creates a follow-up task

- GIVEN a client owned by user `maria`
- WHEN a response linked to that client is submitted with NPS answer 3
- THEN a follow-up task MUST appear in `maria`'s My Work queue referencing the response
- AND `maria` MUST receive a Nextcloud notification produced by the OR notification engine

#### Scenario: Promoter response stays silent

- WHEN a response is submitted with NPS answer 9 and all ratings above threshold
- THEN no follow-up task and no detractor notification MUST be created

#### Scenario: Ownerless client falls back to default assignee

- GIVEN a detractor response linked to a client without an owner
- AND a configured default assignee `jan`
- WHEN the response is processed
- THEN the follow-up task MUST be assigned to `jan`

---

### Requirement: Feature Documentation Conformance

The feature documentation MUST reflect implementation reality: `docs/Features/customer-satisfaction.md` MUST describe V1 as implemented and this change's closed-loop scope with its actual status, and `docs/Features/terugbel-taakbeheer.md` MUST reference the `callback-management` capability instead of claiming an unbacked "Planned" feature.

**Feature tier**: MVP

#### Scenario: Docs no longer claim Planned for shipped capabilities

- WHEN the docs pages are rendered
- THEN `customer-satisfaction.md` MUST NOT carry a bare "Status: Planned" for the V1 engine
- AND `terugbel-taakbeheer.md` MUST point readers at `callback-management`
