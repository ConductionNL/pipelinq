## Why

Pipelinq can send a compliant blast, but it has nowhere for a person to sign up. A segment is a saved query over the CRM: it selects people the tenant already holds, and it can never hold someone who asked to hear from the tenant and is not a customer. That leaves the newsletter with no lawful audience and the recipient with no way in or out other than a provider's own unsubscribe link, which is exactly what rule 1 of the marketing architecture forbids.

Double opt-in is the entry the GDPR expects for a self-service subscribe, and a first-party unsubscribe is what the Gmail and Yahoo bulk-sender rules require from July 2026. Both need an object that records the membership and a signed endpoint the recipient can reach without a Nextcloud account. This change adds them, and it is the core of phase 1 of the marketing programme.

## What Changes

- Two new schemas in a register fragment: `mailingList` (an opt-in container with its own sender identity, opt-in mode and footer address) and `subscription` (one contact's membership of one list, with a `pending → confirmed → unsubscribed` state machine declared as an `x-openregister-lifecycle`).
- `consentRecord` gains `listId` and `evidence`, and a `soft-opt-in` lawful basis that records the objection offered. A list subscription and a channel consent become one ledger rather than two.
- `MailingListService` and `SubscriptionService`: subscribe mints a pending subscription and mails a signed confirmation link through `IMailer`; confirm verifies the token, moves the subscription to confirmed and writes the consent record through `ComplianceService`; unsubscribe withdraws consent through `ComplianceService::recordConsentWithdrawal()`, per list or across every list at once. A soft opt-in import path records an existing customer with its lawful ground and the objection offered.
- Five public endpoints on pipelinq: subscribe, confirm, unsubscribe (GET renders a page, POST performs the one-click withdrawal so RFC 8058 can point at it), and a preference centre that lists the contact's lists with toggles. All are `PublicPage`, signed-token based, throttled, and fail closed.
- Authenticated REST for lists and subscriptions following the `SegmentController` conventions: identity from `IUserSession`, per-object authorization, generic errors.
- A `Lists` entry in the Marketing menu with a declarative index over `mailingList`, a list detail page showing its subscriptions with state chips and counts, a Subscriptions section on the contact detail page, and an admin setting that hands the marketer the public signup embed snippet.
- A blast may target a mailing list as well as a segment. `BlastService` resolves confirmed subscribers only, and `ComplianceService` treats a confirmed subscription as consent for that list, so a pending subscription can never receive a blast.

## Capabilities

### New Capabilities

- `marketing-lists`: mailing lists, subscriptions, double opt-in, soft opt-in, first-party unsubscribe and the preference centre.

### Modified Capabilities

- `marketing-compliance`: a confirmed subscription is consent for that list; `soft-opt-in` joins the lawful bases that permit a send, and it is only valid with the objection recorded.
- `marketing-blast`: a blast may name a `listId` instead of a `segmentId`, and resolves confirmed subscribers only.

## Impact

- **Schemas**: new fragment `lib/Settings/register.d/96-marketing-lists-and-double-opt-in.json`; `consentRecord` extended in place by the same fragment.
- **Backend**: `MailingListService`, `SubscriptionService`, `MailingListController`, `SubscriptionController`, `ListPublicController`; `ComplianceService` and `BlastService` extended; new routes in `appinfo/routes.php`.
- **Frontend**: `src/manifest.d/76-marketing-lists.json`, a subscriptions section component, a subscription modal, an admin settings section, and one pure state helper under `src/services/`.
- **Dependencies**: `IMailer` for the confirmation mail. No new composer or npm dependency. OpenRegister supplies the object store, the lifecycle engine and the credential rules as it already does for the blast engine.
- **Out of scope**: mail transports beyond the instance mailer, the RFC 8058 headers themselves, and the newsletter composer. Each is its own phase-1 change and each depends on this one.
