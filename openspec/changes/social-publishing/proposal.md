## Why

Pipelinq can write an article and send a mailing. It cannot post. A marketer who has just published a customer story retypes it into LinkedIn, asks two colleagues to share it, and then has no answer to what any of that did. The colleagues are the reach that matters: a company page with 900 followers and five spokespersons with 2,000 each is a different thing from a company page alone, and today none of it is measured.

Phase 3 of the marketing programme closes that. An article becomes a post, the post is shaped per network, a person approves it, and a timed job publishes it to the company pages and the personal accounts it names. The numbers come back the next morning.

The thing that made this hard is now built. Rule 2 of the marketing architecture says no secret lives on a Pipelinq object, and until this week there was nowhere else to put one. OpenRegister's credential broker gained an OAuth2 token-set kind with refresh and a connect flow (openregister#3418), and its provider catalogue already ships LinkedIn, Meta Graph, X, Mastodon and Bluesky with the exact allow-rules a social adapter needs. So this change stores a `credentialRef` and shapes requests. It never holds a token and never builds an HTTP client.

Two networks can be proven today. Mastodon registers its application at the account's own server and Bluesky publishes its client metadata, so neither needs anyone's approval to connect. LinkedIn, X and Meta need developer applications filed under Conduction, and those filings are calendar work, not code. The engine is built for all of them anyway, because an adapter written six months after the others is an adapter written against a different set of assumptions. What a missing filing must never do is fail quietly, so a network with nothing filed reports a typed refusal that names what is missing.

## What Changes

- Three new schemas in one register fragment: `socialAccount`, `socialPost` and `socialPublication`. The account carries a `credentialRef` and a `clientId`, both references and neither a secret.
- Connecting an account runs through the broker: a Connect action per network starts the OAuth flow, the callback returns a credential id, and Pipelinq stores that id and nothing else. Reconnect re-authorises the same credential in place so every account pointing at it keeps working. Revoke clears the reference and leaves the publications that already went out intact.
- A personal account belongs to the Nextcloud user who connected it. Only that user or an administrator may publish as them, ask them to share, or revoke the connection, and the publishing job asserts that identity to the broker rather than borrowing a session (ADR-099).
- Seven adapters behind one interface, each shaping a request the broker proxies: Mastodon, Bluesky, LinkedIn, X, Facebook pages, Instagram business and Threads. Each declares its own readiness, so a network with no filing answers `not_configured` with a reason a marketer can read.
- X carries a per-tenant spend budget on the existing `messageSendBudget` semantics, with a hard stop before the call is made. Every post and every metrics read on X is charged, so an exhausted budget refuses rather than spends.
- A composer with per-network variants, media, a link that carries the campaign UTM, a schedule and an approval step, plus a calendar of what goes out when. Publishing runs on a `TimedJob` (ADR-069), per account, and one failing account does not stop the others.
- An advocacy flow for accounts that no application may post to. Personal Facebook and Instagram profiles are the honest example: the owner gets a Nextcloud notification with the prepared text, a copy action and a deep link into the network's own composer, and the share is recorded when they confirm it.
- A daily metrics pull per publication, normalised to views, likes, comments, shares and clicks with the provider payload kept alongside, follower counts per account, and a page that ranks posts by engagement rate per network.

## Capabilities

### New Capabilities

- `social-accounts`: connecting, reconnecting and revoking an account through the broker, the ownership boundary on a personal account, and what an unfiled network reports.
- `social-posts`: the composer, per-network variants, the campaign link, the approval gate, the publishing job, the retry, the X spend stop and the advocacy share flow.
- `social-metrics`: the daily pull, the normalisation, follower counts and the engagement ranking.

### Modified Capabilities

- `marketing-ui`: the Marketing menu gains Social accounts, Social posts and Social performance.

## Impact

- **Schemas**: new fragment `lib/Settings/register.d/98-social-publishing.json`, adding three schemas, three seeded accounts, two seeded posts and one seeded X spend budget on the existing `messageSendBudget` schema.
- **Backend**: `lib/Service/Social/` (the broker gateway, the adapter interface, seven adapters and the registry), `SocialAccountService`, `SocialPostService`, `SocialAdvocacyService`, `SocialMetricsService`, two timed jobs, three controllers, new routes and three notification subjects.
- **Frontend**: `src/manifest.d/78-social-publishing.json`, four in-body sections, one modal, two pure service modules and their registry entries.
- **Dependencies**: none new. The broker is resolved lazily by class name, exactly as `BrokerHttpTransport` already does, so Pipelinq still boots on an instance without OpenRegister.
- **Out of scope**: the read-only social inbox and the connection audit (phase 5), campaigns themselves (phase 4, so `campaignId` is a value a marketer fills in and the existing `CampaignLinkDecorator` already honours), and the hermiq repurpose action that drafts a post from an article (phase 2's own change, which writes into the composer this change builds).
