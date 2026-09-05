# Tasks: marketing-search-intelligence

Phase 5 of the pipelinq marketing programme. Read `design.md` first: the flow-engine gap (no outbound HTTP node) and the cannibalisation guards are the two places a naive implementation goes wrong.

## 1. Schemas and the shipped flow

- [x] 1.1 Add `lib/Settings/register.d/97-marketing-search-intelligence.json` with `keywordTarget`, `competitor`, `competitorWatch`, `watchEvent` and `socialConnection` on the `pipelinq` register, every `required` list satisfiable by the seeded objects, and the scheduled flow as `x-openregister-flows` on `competitorWatch` (`openregister.trigger-schedule` with an explicit `runAs` into `pipelinq.competitor-watch-run` into `openregister.end`, `enabled: false`); verify `python3 -m json.tool` parses it, `npm run check:manifest` exits 0, and `tests/Unit/Settings/CompetitorWatchFlowTest.php` asserts the trigger, the `runAs`, the disabled state and the five-kind enum.
  - **spec_ref**: `specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours`
- [x] 1.2 Add every new schema title, property title and enum label to `l10n/en.json` and `l10n/nl.json` and run `npm run l10n:build`; verify `npm run check:schema-l10n` and `npm run check:l10n-js` exit 0 without moving the baseline.

## 2. The egress seam

- [x] 2.1 Add `lib/Service/Egress/ConnectorEgress.php` and `EgressResult`, resolving the OpenConnector `Source` out of register `openconnector` schema `source` and calling `CallService::call()`, with the closed failure vocabulary `not_configured`, `unavailable`, `refused` and `unparsable`; verify no service added by this change declares `IClientService`, and that an absent OpenConnector answers `unavailable` rather than throwing.
  - **spec_ref**: `specs/marketing-competitor-watches/spec.md#requirement-every-outbound-read-leaves-through-an-openconnector-source`

## 3. Keyword analysis

- [x] 3.1 Add `lib/Service/Search/ExpectedCtrCurve.php`: click-through by integer position with its source in the docblock, linearly interpolated between integers and flat beyond position 20; verify the interpolation, the tail and a position below one.
  - **spec_ref**: `specs/marketing-keyword-intelligence/spec.md#requirement-striking-distance-queries-are-queries-one-push-from-page-one`
- [x] 3.2 Add `lib/Service/Search/KeywordAnalysisService.php` with `positionBuckets()`, `strikingDistance()`, `cannibalisation()` and `contentGaps()`, all pure over rows, every threshold an argument with a default; verify both sides of every boundary (3.0/10.0/20.0, the impression floor, 8.0 and 20.0, the share floor, the materiality margin, all-tokens versus any-token).
  - **spec_ref**: `specs/marketing-keyword-intelligence/spec.md#requirement-queries-are-grouped-into-position-buckets`
- [x] 3.3 Add `lib/Service/Search/HtmlTextExtractor.php` (title and `h1`-`h3`, script and style dropped, entities decoded, plus the CSS-fragment selection the page watch reuses) and `lib/Service/Search/SiteContentCrawler.php` (highest-impression pages first, capped, through `ConnectorEgress`, reporting its reason when it does not run); verify the cap, the ordering, the not-configured path and the extraction.
  - **spec_ref**: `specs/marketing-keyword-intelligence/spec.md#requirement-our-own-pages-are-crawled-through-the-egress-plane`
- [x] 3.4 Add `rows()` to `SearchQueryReportService` exposing its existing paged read, and `lib/Service/Search/KeywordTargetService.php` with the single `confirm()` write path that stamps the proposal kind and leaves `volume` and `difficulty` unset; verify no second reader over `searchQueryDaily` is added and that reading proposals writes nothing.
  - **spec_ref**: `specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it`

## 4. Matomo

- [x] 4.1 Add `lib/Service/Matomo/MatomoReportService.php` reading the campaign, referrer-type and goal reports through the source, gated on the credential reference's broker status, matching campaign rows onto `campaign.utmCampaign` and marking the unmatched ones; verify it refuses on a non-active credential without calling, that every request is a read, and that no GA4, Bing or DataForSEO connector ships.
  - **spec_ref**: `specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference`
- [x] 4.2 Refuse a raw Matomo token in `matomo.credential_ref` at the settings write with a message naming the broker, and add the rest of the intelligence settings keys; verify a 32-character hexadecimal value is refused and a UUID is accepted.
  - **spec_ref**: `specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference`

## 5. Competitor watches

- [x] 5.1 Add the five readers under `lib/Service/Competitor/`: `FeedWatchReader` (RSS and Atom, unparsable is not empty), `SitemapWatchReader` (new versus changed, dateless locations stable, index followed), `PageWatchReader` (CSS fragment, fingerprint stored not text, unmatched selector reported), `FediverseWatchReader` (Mastodon and Bluesky public timelines) and `SearchWatchReader` (hermiq, quiet no-op without it); verify each parse and diff against hand-written documents.
  - **spec_ref**: `specs/marketing-competitor-watches/spec.md#requirement-a-feed-watch-reports-the-entries-it-has-not-seen`
- [x] 5.2 Add `lib/Service/Competitor/RelevanceScorer.php` (hermiq or unscored, never zero, off by default, agent-marked per ADR-088), `WatchEventStore.php` (upsert per watch and URL) and `CompetitorWatchService.php` (due selection, per-watch outcome, one failing watch does not stop the run); verify a second run over unchanged input writes nothing new.
  - **spec_ref**: `specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored`
- [x] 5.3 Add `lib/Flow/CompetitorWatchRunNode.php` and `lib/Flow/PipelinqFlowNodeListener.php`, registered on `RegisterFlowNodesEvent` in `Application.php`, and `lib/Command/CompetitorWatchRunCommand.php` for an on-demand run; verify the node runs only the due watches, never self-scopes (the dispatcher already wraps a contributed node in the run identity, and self-wrapping double-scopes), and that `lib/BackgroundJob/` gained nothing.
  - **spec_ref**: `specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours`

## 6. Connection audit

- [x] 6.1 Add `lib/Service/Social/ConnectionAuditService.php` and `lib/Command/ConnectionAuditCommand.php`: Mastodon and Bluesky answered, every other network `unknown` with a per-row reason and never `false`, results stored as `socialConnection`; verify a hidden Mastodon list is unknown with its own reason and a client with no handle produces no row.
  - **spec_ref**: `specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-answers-only-what-an-api-will-say`

## 7. HTTP surface

- [x] 7.1 Add `KeywordIntelligenceController` (`GET /api/marketing/keyword-proposals`, `POST /api/marketing/keyword-targets`), `CompetitorWatchController` (`GET /api/marketing/watch-events`, `POST /api/marketing/competitor-watches/{id}/run`) and `MarketingConnectorController` (`GET /api/marketing/matomo/report`, `GET /api/marketing/connection-audit`), each with the CRM privilege check the other marketing endpoints use, and register the routes with literal segments ahead of parameterised ones; verify the route-auth and route-reachability gates exit 0 and an unprivileged session is refused on every method.
  - **spec_ref**: `specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it`

## 8. Frontend

- [x] 8.1 Add `src/services/keywordIntel.js` (the pure client-side helpers: bucket labels, shortfall formatting, the confirm payload) with `tests/vitest/keywordIntel.spec.js`, and `src/views/marketing/KeywordIntelligence.vue`, `CompetitorWatches.vue` and `ConnectionAudit.vue`, each reading its page in one request with an empty state that names why it is empty, every `NcSelect` carrying an `inputLabel`, and their `src/registry.js` entries; verify `npm run test:unit` exits 0 and no page fans out per object before it renders (pipelinq#1781).
  - **spec_ref**: `specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time`
- [x] 8.2 Add `src/manifest.d/79-marketing-search-intelligence.json` with Keywords, Competitors and Connection audit under the Marketing group, path-routed, in this change's own fragment so the concurrent campaigns build does not conflict; verify `npm run check:manifest` exits 0.
- [x] 8.3 Add `src/views/settings/MarketingIntelSettings.vue` and mount it in `Settings.vue`; verify the section renders the crawl source, the Matomo fields, the competitor egress source and the relevance switch, and that the credential reference field says the token lives in the broker.
  - **spec_ref**: `specs/marketing-analytics-connectors/spec.md#requirement-marketing-intelligence-settings-hold-the-sources-and-hold-no-secret`

## 9. Docs and verification

- [x] 9.1 Add `docs/user/marketing-search-intelligence.md`, a paragraph in `docs/Features/marketing.md` and the phase-5 outcome in `docs/Technical/marketing-architecture.md`; verify each carries the SPDX header and no em-dash.
- [x] 9.2 Add `tests/e2e/spec-coverage/marketing-search-intelligence.spec.ts` reaching the three pages through path-routed deep links and `revealNavEntry()`, with an `@e2e` annotation per covered scenario; verify `npm run lint` exits 0 and say plainly in the PR that it was not run.
- [x] 9.3 Run the full gate set: `composer check:strict`, `npm run format`, `npm run lint`, `npm run test:unit`, `npm run check:manifest`, `npm run check:spec-links`, `npm run check:schema-l10n`, `npm run check:l10n-js` and the hydra gates with `HYDRA_GATE_BASE_REF=origin/development`; verify each exits 0 and that the gate runner's `App dir:` line names this worktree.
