## Context

See proposal.md for the motivation. The constraints that shape the approach are already in place and are not up for decision here.

- The blast engine exists. `SegmentService` resolves an audience, `ComplianceService` gates it, `BlastService` queues and dispatches it, `TrackingLinkService` signs first-party click tokens and `BlastTrackingController` serves them as throttled `PublicPage` routes. This change adds an audience source and an entry and exit for the people in it. It does not rewrite any of that.
- The marketing architecture (`docs/Technical/marketing-architecture.md`) fixes five rules. Two bind here: unsubscribes and clicks are ours, and no secret sits on an OpenRegister object.
- ADR-031 says declare behaviour in the schema when an `x-openregister-*` extension fits, and write a service only where it does not.
- ADR-108 splits public surface into citizen-facing object access, which belongs to portaliq, and endpoints that are public because the caller is not a browser or because a counterparty's configuration names the URL. ADR-082 says every public endpoint is throttled, and that the attribute alone does nothing.
- ADR-064 keeps secrets out of objects and in the credential broker.

## Goals / Non-Goals

**Goals:**

- One ledger for list membership and channel consent, so a marketer never has to reconcile two answers to "may we mail this person".
- A confirmation and an unsubscribe that work from a mail client with no session, no cookie and no JavaScript.
- An audience source `BlastService` can resolve at send time, with the same shape a segment produces, so nothing downstream branches on where the recipients came from.

**Non-Goals:**

- Mail transports. The confirmation mail goes through `IMailer` and nothing else. Providers and the sender's own Mail account are `marketing-mail-transports`.
- The RFC 8058 headers themselves. This change builds the endpoint the header will name and gives it the verb the RFC requires. Setting the header needs the Symfony message path phase 0 decides.
- The newsletter composer, the article hub and campaigns.

## Decisions

### The state machine is declarative, the state changes are not

`subscription` declares an `x-openregister-lifecycle` on `state` with `pending` as the initial state and four transitions: `confirm`, `unsubscribe`, `resubscribe` and `markBounced`. That is the ADR-031 default and it buys the audit trail of every transition, the RBAC per state and the replay on restore.

What stays imperative is everything the schema grammar cannot express, and each of these is a genuine gap rather than a preference:

| Step | Why no extension fits |
| --- | --- |
| Verify a confirmation token | The check is an HMAC signature plus a `hash_equals` against a stored digest. No declarative guard vocabulary reaches a cryptographic comparison against a value the request carries. |
| Send the confirmation mail | `x-openregister-notifications` dispatches to a Nextcloud user. The recipient here has no Nextcloud account and no user id, only an email address, and the message must carry a token minted for this one subscription. |
| Write the consent record | The consent ledger is a second object whose lawful basis and evidence are derived from how the subscription was created. A lifecycle transition changes one object. |
| Resolve a list audience | Same reason a segment is a service: the answer is a query evaluated at send time, not a stored field. |

So the lifecycle owns *which* transitions are legal and the service owns *whether this caller has earned one*. A transition attempted outside the declared graph is refused by OpenRegister even when a service is wrong.

Alternative considered: express the whole flow as an `x-openregister-flows` engine flow, with the token check as a node. Rejected because the flow engine runs queued off the triggering request and the confirm endpoint must answer the browser synchronously with a page saying whether it worked.

### The subscription stores a digest, never a token

The signed link carries `{p, s, n, iat, exp}`: a purpose, the subscription id, a random nonce, and the timestamps. The subscription stores `confirmTokenHash`, the SHA-256 of the nonce, and the property is marked write-only so it never leaves through the object API.

Confirm therefore has to pass two independent checks. The signature proves the link came from this instance and has not been edited. The digest comparison proves the link is the one minted for this subscription and has not already been spent, because confirming clears the digest. A database read alone cannot forge a link and a stolen signing key alone cannot confirm a subscription whose digest was already cleared.

This is what keeps ADR-064 satisfied without involving the credential broker. A digest is not a secret, so the object may hold it. The signing key is a secret, and it lives where `TrackingLinkService` already puts its own: a per-instance random value in app config, minted on first use, never in the register and never in a fixture.

Alternative considered: store the token itself and compare it directly, which is what a naive read of the architecture document's `confirmToken (writeOnly)` suggests. Rejected. `writeOnly` is an API projection rule, not encryption at rest; a token in a row is a bearer credential in a row, and a register export would carry it. The digest costs one hash and removes the whole class of problem.

### The unsubscribe token outlives the confirmation token

A confirmation link is used within minutes or not at all, so it expires in seven days. An unsubscribe link sits in a mail archive and has to work years later, so it expires in 730 days, admin-overridable. A link that has expired is answered with the same 410 an invalid one gets, and the preference centre link is the recovery path.

### A consent record may be list-scoped, and `findConsentRecord` learned to tell the difference

`consentRecord` gains `listId`. A record with a `listId` gates sends to that list; a record without one is the channel-wide record the existing segment path already consults. `ComplianceService::findConsentRecord()` gains a nullable `listId` argument and filters exactly: `null` matches only records with no list, a string matches only that list. Every seeded record and every record written before this change carries no `listId`, so the existing behaviour is unchanged by construction rather than by care.

This is the one part of the change that touches a hot path, and it is why the argument defaults to `null` rather than being inferred.

Alternative considered: a separate `listConsentRecord` schema. Rejected because the architecture document is explicit that a list subscription and a channel consent are one ledger, and because a DSAR export that has to union two schemas will eventually miss one.

### `soft-opt-in` is a lawful basis that can fail its own check

`soft-opt-in` joins the bases that permit a send, but only when the record's evidence states that an objection was offered. A record claiming the basis without the evidence returns false and says why in the audit log. The alternative, refusing the import, was rejected for a reason worth recording: the import is not always the only writer, and a basis that is unfalsifiable once stored is how "imported" became a problem the existing service already has to log around.

### The public endpoints stay on pipelinq

ADR-108 sends citizen-facing object access to portaliq. These endpoints are the other half of the split. The URL is printed into a mail that may be years old and is named by an RFC 8058 header a receiving provider reads; when a counterparty's configuration names the URL, the URL is not ours to move. Rule 1 of the marketing architecture says the same thing from the product side: the unsubscribe is ours whatever transport sent the mail.

They carry the full ADR-082 pair, both halves, because either alone is inert: `#[AnonRateLimit]` caps the volume and an explicit `IThrottler::registerAttempt()` on every rejected token counts the failures. `BlastTrackingController` is the model, including its distinction between an endpoint that must answer uniformly and one that may refuse.

### The confirmation page is rendered from the controller, not a template

The three GET endpoints answer with a small self-contained HTML document built in the controller, with every interpolated value escaped. A Nextcloud template would pull in the full page shell, a theme and a login-aware header for what is one sentence and one button, and the page has to render for someone who has never seen this instance. The document declares `lang`, uses no JavaScript, and the unsubscribe page's button is a plain form posting to the same URL, so the one-click path works with scripting off.

### The Lists index is declarative, the detail page is not quite

The Lists page is a `type: index` over `mailingList` with no custom component, which is the ADR-031 and ADR-049 default. The detail page is a `type: detail` whose fields auto-render, with one `kind: 'section'` body widget for the subscriptions table. The section exists because a subscription row needs a state chip and the page needs per-state counts, and neither the declarative object-list widget nor `summaryAggregates` expresses a chip vocabulary or a count broken out by enum value. The same component, bound to a contact instead of a list, is the Subscriptions section on the contact detail page.

## Risks / Trade-offs

- **A signing key rotated or lost invalidates every unsubscribe link in every archived mail.** → The key is minted once and never rotated by this change. The preference centre link is the documented recovery path, and the admin settings section says so. A future rotation needs a key-id in the token payload, which the payload has room for.
- **The confirmation mail is the only proof of opt-in and it goes through `IMailer`, which fails quietly on a misconfigured instance.** → A send failure leaves the subscription `pending` and is logged with the list and the address. It does not answer the caller differently, because doing so would leak whether the address exists.
- **A contact can now have several consent records for one channel.** → `findConsentRecord` filters on the list scope exactly rather than taking the first match, and the seeded data proves the unscoped path still resolves. This is the change's main regression surface and it is where the unit tests are densest.
- **Uniform responses make debugging a failed signup harder.** → Every refusal is logged server-side with the reason, the list and a hash of the address. The operator can see what the caller cannot.
- **A blast may now name a list that has no confirmed subscribers.** → The existing `skipped-no-consent` path already handles an empty compliant audience and leaves the blast in draft. No new branch.

## Migration Plan

The register fragment merges onto the existing configuration and adds two schemas plus three properties on `consentRecord`. Adding a property is not a breaking schema change, adding an enum value to `lawfulBasis` is not either, and `x-openregister-lifecycle` on a new schema has no existing rows to validate. Nothing needs a repair step and nothing needs a backfill: a contact with no subscription is simply on no list.

Rollback is removing the fragment. The subscriptions written while it was installed stay as orphaned rows, which is the same posture every other fragment in the directory has.
