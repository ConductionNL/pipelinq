# Design: customer-satisfaction-closed-loop

## Context

V1 (archived `2026-03-22-customer-satisfaction`) provides `survey` + `surveyResponse` schemas, `PublicSurveyController` (show/submit via per-survey UUID token), surveyStore with NPS getters, SurveyList/Detail/Form/Analytics views, and dashboard KPI/trend widgets. V1's design declared email distribution a non-goal. This change adds the distribution, throttling, follow-up, and 360°-aggregation layers around that engine without altering its data model semantics — V1's per-survey token keeps working for manually shared links; invitations add a second, per-recipient token path.

## Architecture

### Data Layer

#### New Schema: `surveyInvitation`

One object per recipient per dispatch. The invitation is the unit of response-rate measurement and the carrier of the personalized response token.

| Property | Type | Required | Description |
|---|---|---|---|
| `token` | string (UUID) | Yes | Unique per-invitation response token. Used in the public URL `/survey/i/{token}`. |
| `surveyRef` | string (FK) | Yes | UUID of the `survey` being sent. |
| `contactRef` | string | Yes | `contactsUid` of the recipient — the existing `contact` schema synced with the NC addressbook (ContactSyncService). Never a new customer record. |
| `linkedEntityType` | string | Yes | Triggering interaction type: `contactmoment`, `request`, `complaint`. |
| `linkedEntityId` | string (UUID) | Yes | UUID of the triggering interaction object. |
| `channel` | string | Yes | Delivery channel: `email`, `whatsapp`, `sms`. Non-email channels require the channel adapter to be configured; otherwise the rule falls back to email or suppresses. |
| `status` | string | Yes | Lifecycle: `scheduled`, `sent`, `responded`, `expired`, `suppressed`, `failed`. |
| `sentAt` | string (timestamp) | No | ISO 8601 UTC, set when the channel hand-off succeeds. |
| `respondedAt` | string (timestamp) | No | Set when the response is submitted with this token. |
| `responseRef` | string (FK) | No | UUID of the resulting `surveyResponse`. |
| `expiresAt` | string (timestamp) | No | Token validity end (default sentAt + 30 days). Expired tokens render a friendly "survey closed" page. |
| `suppressionReason` | string | No | Why dispatch was suppressed: `cooldown`, `opt-out`, `no-channel-address`, `channel-unavailable`. Only on `status = suppressed`. |
| `dispatchRuleId` | string | No | Identifier of the dispatch rule that produced this invitation (for analytics and debugging). |

OpenRegister built-in fields available on all objects (do NOT redefine): `id`, `uuid`, `uri`, `version`, `createdAt`, `updatedAt`, `owner`, `organization`, `register`, `schema`, `relations`, `files`, `auditTrail`, `notes`, `tasks`, `tags`, `status`, `locked`.

#### Schema extensions

- `surveyResponse` + `invitationRef` (string, FK, optional) — links a response to the invitation that produced it. Responses via the V1 per-survey link have no invitationRef.
- `contact` + `surveyOptOut` (boolean, default false) — permanent suppression flag, settable from the public form and the contact detail view.

#### Dispatch rules (configuration, not a schema)

Stored as a JSON array in app config (`SettingsService`), edited from the survey settings UI. Rule shape: `{ id, enabled, trigger: { entityType, statusEquals, channelEquals? }, surveyRef, channel, delayMinutes, cooldownDays, expiryDays }`. Rules are configuration in the admin-settings sense (mirrors how duplicate-prevention settings are stored) — they do not need OR object semantics, versioned audit, or RBAC beyond admin.

### Event Flow

1. OR object event fires for a tracked interaction reaching its terminal status (contactmoment `closed`, request `resolved`/`closed`, complaint `afgehandeld`). The listener subscribes to the same event stream `ObjectEventDispatcher` already consumes — no new event plumbing.
2. `SurveyDispatchService::evaluate(entity)` matches enabled rules; on match it resolves the entity's contact (`contactRef`/`clientRef` → contact → `contactsUid`).
3. Guards, in order: contact resolvable → `surveyOptOut` false → no invitation for this contact with `sentAt` within `cooldownDays` (any survey) → channel address present (email/phone). Any failed guard persists a `suppressed` invitation with the reason — suppressions are first-class data so admins can see *why* volume is low.
4. Passing invitations are created `scheduled`; a background job (existing cron infrastructure, registered via `IRegistrationContext::registerEventListener` + bootstrap job registration as fixed in the 2026-06 jobs sweep) sends those whose delay has elapsed: render link `{base}/apps/pipelinq/survey/i/{token}`, hand off to the channel (email = automation email action infrastructure; whatsapp/sms = channel adapter when configured), set `sent`/`failed`.
5. `PublicSurveyController` gains `showInvitation`/`submitInvitation` (token = invitation token): validates status `sent` and not expired, renders the V1 form, on submit creates the `surveyResponse` with `invitationRef` + the invitation's entity/contact linkage, flips invitation to `responded`. Re-use V1 brute-force protection on the public routes.
6. On response save, `DetractorFollowUpService` classifies: any NPS answer ≤ 6 or any 1–5 rating answer ≤ 2 → detractor. Creates a My Work follow-up task (assignee = linked client's owner, else configured default) referencing the response, and relies on a schema-rule notification (x-openregister-notifications dialect, ADR-031) on `surveyResponse` creation with detractor condition for the Nextcloud notification — no imperative `INotificationManager` calls in app code.

### Aggregation

`SatisfactionAggregationService` computes, on the fly (V1 decision 4 — no pre-aggregated scores):
- Response rate = responded / sent per survey, channel, period (suppressed and failed excluded from the denominator).
- Per-client satisfaction: responses whose invitation's linked entity belongs to the client (or whose response `linkedEntityId` resolves to the client) → NPS, average rating, count, trend (current vs. previous 90-day window), recent verbatims (latest 3 open-text answers).

Customer-360's client view renders this as a "Satisfaction" panel; empty state when the client has no responses.

## Decisions

1. **Per-invitation token alongside the V1 per-survey token** — invitation tokens give per-recipient response tracking and duplicate prevention without breaking V1's manually-shared links. Two public route families, one form component.
2. **Suppressions are persisted invitations, not silent skips** — response-rate debugging requires seeing cooldown/opt-out/no-address volumes; a dropped event is invisible.
3. **Cooldown is per-contact across all surveys** — fatigue is a property of the person, not the survey. Default 30 days, admin-configurable per rule (the strictest matching rule wins).
4. **Dispatch rules in app config, not an OR schema** — small admin-owned configuration consistent with existing SettingsService patterns; avoids schema sprawl for non-domain data.
5. **Notifications via ADR-031 dialect only** — the detractor alert is a schema-rule on `surveyResponse`; the in-app task is domain data (My Work), the notification is the OR engine's job.
6. **English i18n source keys** — all new UI strings use English keys (`t('pipelinq', 'Response rate')`), Dutch in `l10n/nl.json`.

## Risks / Trade-offs

- **Public endpoint surface grows** — mitigated by reusing V1 brute-force protection, UUID tokens, expiry, and single-use (`responded` invitations reject re-submission).
- **Channel adapter optionality** — WhatsApp/SMS rules on instances without the adapter degrade to `suppressed (channel-unavailable)` or email fallback (rule flag); dispatch never hard-fails the triggering interaction's save.
- **Detractor threshold opinionation** — NPS ≤ 6 follows the standard promoter/passive/detractor bands already in V1; the 1–5 rating threshold (≤ 2) is admin-overridable.
- **Aggregation cost in customer 360** — on-the-fly computation over a client's responses is bounded (responses per client are low-volume); revisit pre-aggregation only if profiling demands it.
