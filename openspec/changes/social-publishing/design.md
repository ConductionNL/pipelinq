# Design

## What was verified before anything was written

Every claim below was read out of `openregister` at `origin/development` after `aaa4bb6f0` (the merge of openregister#3418), not from the proposal that asked for it.

| Claim | Verified | Where |
| --- | --- | --- |
| `POST /apps/openregister/api/credentials/oauth2/start` returns `{authorizationUrl, expiresIn}` | yes | `CredentialOauth2Controller::start()`, and the route is declared under the literal `/api/credentials/oauth2/` prefix ahead of `/api/credentials/{id}` |
| `credentialId` re-authorises in place | yes, and it is gated: `OAuth2ConnectionRepository::findManageable()` refuses a credential the caller cannot manage | `CredentialOauth2Controller::buildClaims()` |
| `CredentialBrokerService::request(...): array{status, headers, body}` | yes, with that exact signature and `$actingUserId` last | `CredentialBrokerService::request()` |
| `CredentialRelinkRequiredException` on a dead grant | yes, and it EXTENDS `CredentialAccessDeniedException`, so it must be caught FIRST or a generic catch swallows it | `CredentialRelinkRequiredException` |
| `resolveInjectable()` returns null for this kind | yes, by design: a host-locked provider is proxy-only and its secret never leaves OpenRegister | `CredentialBrokerService::resolveInjectable()` |
| Status is read through `ObjectService::find(id, register: REGISTER, schema: SCHEMA)` | yes, `credential-broker` / `brokeredcredential` | `CredentialBrokerService::REGISTER`, `::SCHEMA` |

Two things differ from the brief and change the design:

**1. The provider catalogue already ships the five social providers, with allow-rules.** `lib/Settings/credential-providers.json` declares `mastodon`, `bluesky`, `linkedin`, `x` and `meta-graph`, each with an `allowRules` list naming the exact method and path pattern a credential may ever call. Those rules are the contract this change's adapters are written against, so `MastodonAdapter` posting to `/api/v1/statuses` is not a guess: it is the one write rule the catalogue admits. It also means an adapter that invents a path fails closed at the broker rather than reaching the network. Nothing here re-declares a host, and the adapters pass a path, never a URL.

**2. Bluesky is flagged `preview` upstream and cannot be proven end to end yet.** The catalogue entry says so in its own `$comment`: AT Protocol requires DPoP-bound access tokens, the DPoP proof layer is not implemented, and a Bluesky access token will be refused by a personal data server until the follow-up (`credential-oauth2-bluesky-dpop`) lands. The brief asked for Mastodon and Bluesky to be the two that prove the engine. Mastodon does. Bluesky's adapter is written and unit-tested against the AT Protocol request shape, and the connection can be minted, but the publish will be refused by the PDS until the broker gains DPoP. That is reported as a `preview` readiness on the account rather than hidden, and it is not `not_configured`: nothing is missing on the Pipelinq side, so blocking the call would mean a later broker release could not switch it on without a Pipelinq change.

## The five decisions

### 1. The broker is the egress plane for a credential-bearing call

Rule 3 of the marketing architecture says every call to a network leaves through a shared egress plane and Pipelinq writes adapters, not HTTP clients. For a call that carries a grant, the broker is that plane: it owns the host through its host-lock, it owns the `Authorization` header, and it refuses any path its allow-rules do not name. An OpenConnector source in front of it would add a second host that could disagree with the locked one and a second place a token could be pasted, which is exactly what ADR-064 exists to prevent.

So `SocialBrokerGateway` hands `{method, path, headers, body}` and a credential id to `CredentialBrokerService::request()`. No `IClientService` is constructed anywhere in this change. `BrokerHttpTransport` is the precedent (`pos-psp-keys-via-broker`) and the shape is deliberately the same, with one difference: that transport answers `status 0` on every failure, which cannot tell "the broker is absent" from "the network said no". A publish has to tell those apart to know whether a retry can help, so this gateway returns a typed outcome instead.

### 2. A failure is one of a closed set, never a silent nothing

`SocialGatewayResult` carries a `failureCode` from a closed list, and `socialPublication.failureCode` stores it:

| code | means | what the interface offers |
| --- | --- | --- |
| `not_configured` | no developer application is filed for this network, or the account has no credential | nothing to retry; the reason names the filing |
| `relink_needed` | the grant is gone (`CredentialRelinkRequiredException`) | Reconnect |
| `budget_exhausted` | the tenant's X spend budget is reached | raise the budget or wait for the period |
| `not_permitted` | the broker refused (`CredentialAccessDeniedException`) | check who owns the account |
| `rejected_by_network` | a 4xx from the network itself | fix the post and retry |
| `unavailable` | a 5xx, a transport failure, or the broker is not installed | retry |

`CredentialRelinkRequiredException` extends `CredentialAccessDeniedException`, so the gateway catches the relink type first. Catching the parent first would turn every dead grant into `not_permitted` and hide the one failure a person can actually fix. That ordering is asserted by a unit test rather than left to reading order.

### 3. Identity comes from the account, and the job asserts it

A personal account is published as its owner. The publishing job has no session, so it passes `socialAccount.ownerUserId` as `$actingUserId` to the broker, which is the sessionless in-process path the broker documents for exactly this. That is ADR-099's rule applied here: the identity a run executes as is a property of the run's subject, not of whoever happens to be logged in and not of whoever authored the post. `ownerUserId` is stamped from the session by the connect path and is never accepted from a request body, so the field the job trusts is one no client can write.

The approval is a separate record. `approvals[]` says which person decided and when; `ownerUserId` says whose rights the call runs with. Conflating them would let an approver publish as somebody else.

### 4. Variants override, they do not replace

A post has one `body` and a `variants` map keyed by network. Resolving a variant is a merge, not a swap: a variant that carries only a `body` still gets the post's `link` and `media`. The alternative, a full copy per network, drifts the moment somebody fixes a typo in one of five places. The resolution is a pure function in `src/services/socialComposer.js` and mirrored in `SocialPostService::resolveVariant()`, so the composer's preview is produced by the same rule that will do the sending, and both are tested against the same table.

Per-network length limits live in the same pure module. A variant over its network's limit is refused at approval time rather than at publish time, because a refusal three hours after the marketer left is a refusal nobody sees.

### 5. The performance page renders first and counts second

pipelinq#1781 fixed a page that awaited a per-object fan-out before it rendered anything. The ranking page here reads `socialPublication` rows in one filtered query and computes engagement rate from `metrics` and the `followerCount` already copied onto the account by the daily pull. It never walks publications to fetch their accounts. Division by a zero follower count yields no rate rather than an error, and a publication with no metrics yet sorts last instead of being dropped.

## The advocacy flow, and why it is not a workaround

Meta's platform policy is not a limitation to route around: no application may post to a personal Facebook or Instagram profile, and no amount of app review changes that. The honest design is to stop trying. An account with `publishMode: share` produces a publication in `awaiting_share`, and the owner gets a Nextcloud notification carrying the prepared text, a copy action and a deep link into the network's own composer. When they confirm, the publication becomes `shared` with the moment they confirmed.

Only the owner may confirm. A colleague marking somebody else's share as done would put a number in a report that nobody can trace to a post that exists.

## Schema notes

`socialPost.status` is a plain enum rather than an `x-openregister-lifecycle`. ADR-031 says declare what the grammar can express, and the grammar cannot express "published to three accounts of five". The post's status is derived from its publications by `SocialPostService::settle()`, which is where that rule can be read once and tested.

`socialAccount.status` mirrors the broker's own vocabulary (`pending`, `active`, `expired`, `relink_needed`, `disabled`) and adds one value of its own, `not_configured`, for a network with no filing. Mirroring rather than inventing means a `relink_needed` here and a `relink_needed` on the broker's credential are the same state, and the reconnect action is the same action.

`socialPublication` rows are created for every named account before anything is sent. A failure then has a row to be recorded on rather than only a log line to be lost in.
