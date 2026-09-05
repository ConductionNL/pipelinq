## 1. Schemas and seed data

- [x] 1.1 Add `lib/Settings/register.d/98-social-publishing.json` with `socialAccount`, `socialPost` and `socialPublication`, the three slugs on the `pipelinq` register, three seeded accounts (one Mastodon company page, one LinkedIn spokesperson, one share-mode personal Instagram), two seeded posts and one seeded hard-stop `x` spend budget on the existing `messageSendBudget` schema; verify `python3 -m json.tool` parses it and `npm run check:manifest` exits 0.
- [x] 1.2 Add every new schema title, property title and enum label to `l10n/en.json` and `l10n/nl.json` and run `npm run l10n:build`; verify `npm run check:schema-l10n` and `npm run check:l10n-js` exit 0 without moving the baseline.

## 2. The broker seam

- [x] 2.1 Add `lib/Service/Social/SocialGatewayResult.php` carrying the closed failure-code set and `lib/Service/Social/SocialBrokerGateway.php` resolving OpenRegister's broker lazily by class name, passing `{method, path, headers, body}` and never a host or an authorization header, and catching `CredentialRelinkRequiredException` BEFORE `CredentialAccessDeniedException` because the first extends the second and the wrong order turns every dead grant into a permission refusal; verify a broker-less instance yields `unavailable` rather than an exception, and that the ordering itself is asserted.
- [x] 2.2 Add `readiness()` to the gateway, reporting `ready`, `preview` or `not_configured` per broker provider from the catalogue, so a network with no filing is refused with a reason instead of failing at the call; verify Threads reports `not_configured` and Bluesky reports `preview`.

## 3. Adapters

- [x] 3.1 Add `SocialNetworkAdapter` (the interface), `SocialPublishRequest`, `SocialPublishOutcome` and `SocialAdapterRegistry` under `lib/Service/Social/`, each adapter declaring its network, its broker provider, its body limit and its media rules; verify the registry answers every network the schema enum names.
- [x] 3.2 Add `MastodonAdapter` (`POST /api/v1/statuses`) and `BlueskyAdapter` (`POST /xrpc/com.atproto.repo.createRecord` with an `app.bsky.feed.post` record), both against the paths OpenRegister's provider catalogue allows; verify the request-shape tests assert method, path and body against the documented API.
- [x] 3.3 Add the five adapters whose networks wait on a filing: `LinkedInAdapter` (`POST /rest/posts`, member `urn:li:person:` or organisation `urn:li:organization:` author), `XAdapter` (`POST /2/tweets`), `FacebookPageAdapter` (`POST /v21.0/{page-id}/feed`), `InstagramBusinessAdapter` (container then `media_publish`) and `ThreadsAdapter` (container then publish, reporting `not_configured` because no Threads provider is filed); verify each shapes the documented request, none sets a host, and Instagram never publishes when the container step failed.

## 4. Services and jobs

- [x] 4.1 Add `lib/Service/SocialAccountService.php` with connect, reconnect, revoke and status sync through the broker's connect endpoints, stamping `ownerUserId` from the session, refusing any secret in a connect response, and holding the per-object guard every account and post mutation uses (the owner of a `person` account or an administrator, on `ObjectOwnerAccessPolicy`); verify no test can get a token onto a stored account and a second user is refused on publish, reconnect, revoke and share confirmation.
- [x] 4.2 Add `lib/Service/SocialPostService.php` with variant resolution, the campaign link decoration through the existing `CampaignLinkDecorator`, the approval and rejection paths, per-account publication rows, the settle rule that derives the post's status from its publications, and retry; and gate every X publish and X metrics read on `BudgetService::canSend()` before the call and `recordSend()` after it under the `x` provider id; verify the stored link stays undecorated and an exhausted hard stop refuses without reaching the gateway.
- [x] 4.3 Add `lib/Service/SocialAdvocacyService.php` creating `awaiting_share` publications, notifying the account owner with the prepared text and the network's own composer deep link, and recording the confirmation; verify nothing outbound happens on the share path.
- [x] 4.4 Add `lib/Service/SocialMetricsService.php` normalising each network's payload to views, likes, comments, shares and clicks, keeping the raw payload, refreshing follower counts, and ranking by engagement rate with no division by zero; verify one failing pull does not stop the rest.
- [x] 4.5 Add `SocialPublishJob` (every five minutes) and `SocialMetricsPullJob` (daily) under `lib/BackgroundJob/` per ADR-069, both asserting the account owner to the broker rather than borrowing a session (ADR-099); verify the publish call carries the owner and not the approver.

## 5. HTTP surface

- [x] 5.1 Add `SocialAccountController`, `SocialPostController` and `SocialAdvocacyController` following the `ArticleController` conventions (identity from `IUserSession`, the per-object guard on every method, one generic refusal) and register their routes in `appinfo/routes.php` with literal-suffixed paths ahead of the bare parameterised ones; verify an unprivileged session is refused on every mutation and the route-auth and route-reachability gates exit 0.

## 6. Interface

- [x] 6.1 Add `src/services/socialNetworks.js` (the per-network catalogue, limits, status vocabulary and composer deep links) and `src/services/socialComposer.js` (variant resolution and the fit check, mirroring `SocialPostService`), both pure; verify `tests/vitest/socialComposer.spec.js` covers the merge, the limit and the unknown network.
- [x] 6.2 Add `src/manifest.d/78-social-publishing.json` with Social accounts (order 60), Social posts (order 62) and Social performance (order 64), a declarative index and detail page per object; verify `npm run check:manifest` exits 0 and the pages are path-routed.
- [x] 6.3 Add `src/modals/SocialPostComposeModal.vue` and the four in-body sections (`SocialAccountsSection`, `SocialPostVariantsSection`, `SocialPublicationsSection`, `SocialRankingSection`) with their registry entries, every `NcSelect` carrying an `inputLabel`; verify the modal-isolation and input-label gates exit 0.

## 7. Tests and documentation

- [x] 7.1 Add PHPUnit coverage for one request shape per network, the relink catch ordering, the budget stop, the consent-free advocacy path, per-object authorization and the metrics normalisation; verify `composer test:all` exits 0.
- [x] 7.2 Add `tests/e2e/spec-coverage/social-publishing.spec.ts` reaching the three pages through `revealNavEntry()` and path-routed deep links, with an `@e2e` annotation per covered scenario; verify `npm run lint` exits 0 and say plainly in the PR that it was not run.
- [x] 7.3 Add `docs/user/social-publishing.md`, a paragraph in `docs/Features/marketing.md` and the phase-3 row in `docs/Technical/marketing-architecture.md`; verify each carries the SPDX header and no em-dash.
- [ ] 7.4 Run the full gate set: `composer check:strict`, `npm run format`, `npm run lint`, `npm run test:unit`, `npm run check:manifest`, `npm run check:spec-links`, `npm run check:schema-l10n`, `npm run check:l10n-js` and the hydra gates with `HYDRA_GATE_BASE_REF=origin/development`; verify each exits 0.
