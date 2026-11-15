# Design

## What was verified before anything was written

Every claim below was read out of the checkout, not out of the proposal that asked for it.

| Claim | Verified | Where |
| --- | --- | --- |
| The Search Console side already ships and is idempotent per (property, date, query, page) | yes | `SearchQueryDailyStore::upsert()`, `SearchConsoleImportService::importProperty()` |
| The aggregation over a window already exists and is impressions-weighted | yes | `SearchQueryReportService::aggregate()` |
| The rows are read from OpenRegister with `_rbac` and `_multitenancy` off, paged at 500, capped at 20000 | yes | `SearchQueryReportService::pages()` |
| Pipelinq calls an OpenConnector source through `CallService::call(source, endpoint, method, config)` and never builds an HTTP client for a provider | yes | `ConnectorSourceTransport::send()`, `::resolveCallService()` |
| An OpenConnector `Source` is an OpenRegister object in register `openconnector`, schema `source` | yes | `ConnectorSourceTransport::OPENCONNECTOR_REGISTER_SLUG` |
| Pipelinq can read a brokered credential's status without ever seeing its secret | yes | `BrokerCredentialReader::status()`, whose `READABLE` list has no token field |
| A leaf app contributes flow nodes by listening for `RegisterFlowNodesEvent` and calling `registerNode()` | yes | humaniq's `HumaniqFlowNodeListener`; the event is `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent` |
| `IFlowNode` is `getId`, `getDisplayName`, `getDescription`, `getIcon`, `isAvailableForScope`, `validateConfig`, `execute` | yes | `openregister/lib/Service/Flow/IFlowNode.php` |
| A flow ships declaratively as `x-openregister-flows` inside a schema's `configuration` | yes | humaniq's `PayrollRun` schema in `register.d/hr-objects.json` |
| **The flow engine has no outbound HTTP node** | yes, still true | `openregister/lib/Service/Flow/Nodes/` has 27 nodes and not one of them fetches a URL; ADR-094 decision 3 said so in August and nothing has changed it |
| Hermiq's web search is `WebSearchClient::search(string $query, ?string $actingUserId): array` answering `{query, results[]}` or `{error: {code, message}}` | yes | `hermiq/lib/Service/WebResearch/WebSearchClient.php` |
| Hermiq's text completion is `ProviderFactory::generateText(string $prompt, ?string $userId, bool $allowNextcloud, ?string $organisation): string` | yes | `hermiq/lib/Service/Llm/ProviderFactory.php` |

## The seven decisions

### 1. A watch is an OpenRegister flow schedule, and the fetching node is ours

ADR-094 says new scheduled automation targets OpenRegister's flow engine and not n8n, and decision 3 of that same ADR records the gap that makes a naive reading impossible: the node registry has no outbound-HTTP node. A watch that fetches a feed therefore cannot be built out of stock nodes.

The resolution is the one humaniq already used for payroll: **the schedule is the engine's, the work is ours.** Pipelinq contributes one node, `pipelinq.competitor-watch-run`, through `RegisterFlowNodesEvent`. The shipped flow is `openregister.trigger-schedule` to that node to `openregister.end`, and it is declared on the `competitorWatch` schema as `x-openregister-flows`. The node reads the due watches and runs them.

This is not a bespoke cron with extra steps. What the engine owns is exactly what a cron would otherwise own in our code: when it fires, whether a second run may overlap, whose identity it runs as, and the record that it ran. There is **no `TimedJob` in this change**. That is the observable difference, and the tasks list checks for it.

The flow arrives disabled, per the engine's adoption contract, and the schedule node carries an explicit `runAs`. OpenRegister's `TriggerScheduleNode` resolves `runAs` against `IUserManager` and there is no owner fallback, so a flow shipped without it validates and then never runs as anybody. The shipped flow therefore names a placeholder account the tenant repoints, and the node refuses a run with no acting identity rather than reading the register as nobody.

`competitorWatch.schedule` stays on the object as well. It is what the node uses to decide which watches are due on this firing, so one flow can drive an hourly page watch and a daily feed watch without one flow per watch.

### 2. One egress seam for every outbound read

Rule 3 of the marketing architecture and ADR-067 put every outbound call behind an OpenConnector source. This change makes four kinds of outbound read (crawl our own pages, Matomo's Reporting API, a competitor's feed or sitemap or page, a public fediverse timeline) and it would be four HTTP clients if each service grew its own.

`ConnectorEgress` is the single seam: given an app-config key naming a source id, it resolves the `Source` object out of register `openconnector` and hands `{endpoint, method, config}` to `CallService::call()`, returning a typed `EgressResult` of `{ok, status, body, failure}`. Every service in this change goes through it, and none of them constructs an `IClientService`. When no source is configured, or OpenConnector is absent, the result is `not_configured` or `unavailable` and the caller reports that reason to the page rather than an empty list.

The failure vocabulary is closed and small, because the pages have to say something useful:

| code | means |
| --- | --- |
| `not_configured` | no source id is set for this capability |
| `unavailable` | OpenConnector is not installed, the source does not resolve, or the call threw |
| `refused` | the source answered with a non-2xx status |
| `unparsable` | the body came back but is not the format the reader expects |

An empty result and a failure are never the same value. This is the whole reason the gap detector reports `crawled: false` instead of "no gaps found".

### 3. The keyword derivations are pure functions over rows

`KeywordAnalysisService` takes an array of `searchQueryDaily` rows and returns findings. It resolves no services, reads no config and touches no register. Everything it needs (the impression floor, the position band, the shortfall margin) arrives as an argument with a documented default.

That is what makes the arithmetic testable at all. The four derivations are the substance of this change and each is a predicate that can be wrong in a way no integration test would notice, so each one is a table-driven unit test over hand-written rows, including both sides of every boundary.

`SearchQueryReportService` gains one public method, `rows()`, exposing the paged read its own `topQueries()` already performs. The alternative was a second reader over the same schema, which is precisely the second store the phase was told not to build.

**Position buckets.** Grouped per query by the impressions-weighted mean position the existing `aggregate()` already computes. The boundary belongs to the lower bucket: at or below 3.0 is `1-3`, at or below 10.0 is `4-10`, at or below 20.0 is `11-20`, everything above is `21+`. A query with zero impressions has no position and is counted in none of them.

**Striking distance.** A query qualifies when its impressions over the window reach the floor (default 100), its weighted position lies between 8.0 and 20.0 inclusive, and its click-through is **below** what that position normally earns. "Normally earns" is `ExpectedCtrCurve`: a published, deliberately conservative curve of click-through by integer position, linearly interpolated for the fractional positions Search Console actually reports, flat below position 20. The curve is a documented constant with its source in the docblock, not a magic number sprinkled through a comparison, so a tenant that disagrees with it changes one table.

**Cannibalisation.** The naive predicate ("combined click-through is below the better page alone") is true for almost every query with two pages, because a combined rate is a weighted average and a weighted average is below its maximum whenever the terms differ. Shipping that would flag everything and mean nothing. The predicate therefore adds two guards, both derived from what makes a finding actionable:

- the second page must carry a real share of the query's impressions (default 20 percent), so one stray page with three impressions is not a finding;
- the loss must be material: the combined click-through must be at or below the better page's rate times one minus a margin (default 0.10).

Both guards are arguments with defaults, and the tests assert that a query just inside each guard is flagged and one just outside is not.

**Content gaps.** A query with impressions at or above the floor where no crawled page's title or headings carry the term. "Carry" is: normalise both sides (lowercase, strip diacritics, collapse whitespace), drop tokens shorter than three characters and a small Dutch and English stop-word list, and require **every** remaining token of the query to appear in the page's title-and-headings text. Requiring all tokens rather than any is deliberate: "woo verzoek indienen" is not answered by a page whose title merely contains "verzoek".

### 4. Every finding is a proposal, and a proposal is not a record

`KeywordAnalysisService` returns findings. It writes nothing. `KeywordTargetService::confirm()` is the only path that creates a `keywordTarget`, it runs from an authenticated request with the CRM privilege check every other marketing endpoint uses, and it stamps the proposal kind that produced it so the target can be traced back.

This is rule 4 of the marketing architecture applied to analysis rather than to sending. It also has a plainer justification: a striking-distance list recomputed daily would create and delete targets under the marketer's hands, and a target is a commitment somebody is going to write a page against.

`volume` and `difficulty` stay empty. They are the DataForSEO fields, DataForSEO is out of scope, and a field silently left at zero reads as "no search volume" rather than "not measured".

### 5. The Matomo token is a reference, and the settings refuse a token

Rule 2 is absolute: no secret on an object and none in a plain setting. `matomo.credential_ref` holds a brokered credential's UUID and `MatomoReportService` reads only its **status** through `BrokerCredentialReader`, which cannot return a secret because its `READABLE` allow-list has no token field in it. The call itself goes out through the OpenConnector source named by `matomo.source_id`, which is where the credential is actually resolved and injected.

Two guards make that more than an intention:

- the settings write refuses a `matomo.credential_ref` that looks like a raw Matomo token (Matomo's `token_auth` is 32 hexadecimal characters), with a message naming the broker. A pasted token is the single most likely way this rule gets broken, and it is silent otherwise;
- the report service refuses to call at all when the credential's status is not `active`, reporting `relink_needed` or `not_configured`, so a dead grant is a message and not a 401 buried in a call log.

Three reports are read: campaigns, referrer types and goals. Matomo recognises `mtm_*` and `utm_*` alike, so the campaign rows are matched back onto the `campaign.utmCampaign` values `CampaignService` already mints rather than onto a second vocabulary. A Matomo campaign that matches no pipelinq campaign is still shown, marked unmatched, because that is usually somebody spending money outside the tool.

### 6. Competitor watching is scoped by what is legitimate, and the scope is written down

Five kinds ship: `rss` (RSS 2.0 and Atom), `sitemap` (new and changed `<loc>` entries), `page` (a CSS-selected fragment, diffed), `fediverse` (Mastodon and Bluesky public timelines) and `search` (hermiq's web search on a saved query). LinkedIn and Meta are excluded because no legitimate machine access to another organisation's posts exists on either, and the spec says so rather than leaving it as an obvious gap for the next person to close by scraping.

The reader for each kind is its own class with one job and a pure parse step, so the parsing is unit-testable without a network: `SitemapWatchReader::diff()` takes two sitemap documents and returns new and changed locs; `PageWatchReader::diff()` takes two fragments and returns a summary; `FeedWatchReader::parse()` takes a feed document and returns entries. The fetching happens above them, in `ConnectorEgress`.

Idempotence is the same rule as the Search Console import: `WatchEventStore` upserts a `watchEvent` per (watch, url), so a re-run of the same window changes nothing and a flaky schedule cannot flood the page with duplicates.

The page watch stores a fingerprint of the selected fragment rather than the fragment itself. A watch is not an archive of somebody else's page, and storing the text would make Pipelinq the copy.

### 7. Relevance degrades to unscored, never to zero

`RelevanceScorer` asks hermiq to score an event from 0 to 100 against the tenant's own description of what matters. When hermiq is absent, when its provider is unconfigured, or when it answers something that is not a number in range, the event is stored **without** a `relevanceScore` and the page sorts it by date.

A score of zero would be a lie with the same shape as a real answer: the page would sort the unscored events to the bottom and the marketer would never see them. The absence of a field is the honest value, and the page says "not scored" where it renders.

Scoring is also off by default (`competitor.relevance`), because it sends a competitor's headline to whatever model the tenant configured and that is a decision an admin makes on purpose.

## Connection audit: what can be known and what cannot

Phase 3 connected accounts. This audit joins them to the handles clients carry (`client.socialProfiles[]`, from `contact-channel-details`) and answers two questions per pair.

| Network | Do we follow them | Do they follow us | Why |
| --- | --- | --- | --- |
| Mastodon | yes | yes | `/api/v1/accounts/lookup` then `/following` and `/followers`, public on a default instance |
| Bluesky | yes | yes | `app.bsky.graph.getFollows` and `getFollowers`, public on the AppView |
| LinkedIn | unknown | unknown | The Community Management API exposes an organisation's own followers as counts, not as a list that can be searched for one member |
| X | unknown | unknown | Follower lookup sits behind a paid tier this fleet does not buy |
| Facebook, Instagram, Threads | unknown | unknown | Meta exposes no follower list for a page or a business account |

`unknown` is stored with its reason, and the page renders the reason. Reporting `false` where the truth is "the API will not say" would be an answer the marketer acts on, and it would be wrong roughly half the time.

An instance that has hidden its follower lists returns `unknown` too, on the same path, which is why the reason is a per-row value rather than a per-network constant.

## What this change cannot prove without a credential

Stated up front so the PR does not have to be read for it.

| Provable on a bare instance | Needs a credential or a live service |
| --- | --- |
| Every keyword derivation, over hand-written rows | Search Console rows arriving at all |
| The sitemap, page and feed parse and diff | Any actual fetch |
| The Matomo request shape, the credential-reference refusal, the not-configured and relink paths | A Matomo instance answering |
| The gap detector's not-crawled path and its all-tokens rule | A crawl through a real source |
| The relevance degrade path | Hermiq scoring anything |
| The connection audit's unknown vocabulary and its reasons | Mastodon or Bluesky answering |
| Every page's empty state, and the routes | Any of the above populated |
