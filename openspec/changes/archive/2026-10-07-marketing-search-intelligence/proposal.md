# Proposal: marketing-search-intelligence

Phase 5 of the pipelinq marketing programme (`docs/Technical/marketing-architecture.md`). Phase 2 of the fleet traffic analytics programme already taught Pipelinq to import Search Console rows and list the top queries. This change turns that data into decisions, adds a second analytics source the tenant hosts itself, and gives the marketer a cheap, legitimate view of what the competition published.

## Problem

1. **The imported search data answers nothing.** `searchQueryDaily` holds one row per property, day, query and page, and the Search queries page sorts them by clicks. Sorting by clicks tells you what already works. It does not tell you which query sits at position eleven and would earn traffic with one paragraph, which two pages are competing for the same query and costing each other clicks, or which query people type that no page of ours answers at all.
2. **There is no second source.** Search Console reports what happened in Google's results. It says nothing about what visitors did after the click, and a public-sector tenant cannot put a third-party tracker on the site to find out. Matomo is the first-party answer the fleet already runs, and nothing reads it.
3. **Competitor activity is invisible or illegitimate.** A marketer wants to know what a competitor published this week. The obvious sources (LinkedIn, Meta) have no legitimate machine access for that, and reaching for one is how a tenant ends up scraping. The legitimate sources (feeds, sitemaps, page changes, the fediverse, a plain web search) exist and nobody watches them.
4. **We do not know whether we follow our own clients.** Phase 3 connected corporate and personal social accounts. Nothing joins those accounts to the handles a client carries, so "are we even following our biggest customer" is a question answered by hand, per person, per network.

## Solution

1. **Keyword analysis over the data already imported.** `KeywordAnalysisService` derives four things from `searchQueryDaily` rows alone: position buckets (1-3, 4-10, 11-20, 21+), striking-distance queries (enough impressions, position 8 to 20, click-through below what that position normally earns), cannibalisation (two pages ranking for one query where the combined click-through is materially below the better page on its own), and content gaps (a query with impressions where no page's title or headings carry the term). Every finding is a **proposal**. A marketer confirms one into a `keywordTarget`; nothing auto-creates a target.
2. **A crawl of our own pages, through the egress plane.** The gap detector needs to know what our pages say. `SiteContentCrawler` fetches the pages that already appear in `searchQueryDaily` through an OpenConnector source and keeps their title and headings. Without a configured source it crawls nothing and the gap section reports why, rather than reporting an empty list that reads like "no gaps".
3. **A Matomo connector.** `MatomoReportService` reads campaign, referrer and goal reports from Matomo's Reporting API through an OpenConnector source. The token is a `credentialRef` into the OpenRegister broker, never a plain setting; a value that looks like a raw token is refused at the settings write. Matomo recognises both `mtm_*` and `utm_*`, so the campaign rows are matched back to the `campaign.utmCampaign` values the campaigns change already mints.
4. **Competitor watches on OpenRegister flow schedules.** `competitor` and `competitorWatch` objects describe what to watch; a scheduled flow fires `pipelinq.competitor-watch-run`, which reads the due watches and writes `watchEvent` rows. Five kinds: RSS and Atom feeds, sitemap diffs, a page watch over a CSS-selected fragment, Mastodon and Bluesky public timelines, and a scheduled web search through hermiq. Relevance scoring asks hermiq where hermiq is available and leaves the event unscored where it is not.
5. **A connection audit.** For each client carrying a social handle, `ConnectionAuditService` reports whether we follow them and whether they follow us, built from the accounts phase 3 connected. Two networks answer that question through a public API. The rest are reported as `unknown` with the reason, never as `false`.

## Scope

- New schemas in `lib/Settings/register.d/97-marketing-search-intelligence.json`: `keywordTarget`, `competitor`, `competitorWatch`, `watchEvent`, `socialConnection`, plus the scheduled flow on `competitorWatch`.
- New services under `lib/Service/Search/`, `lib/Service/Matomo/`, `lib/Service/Competitor/` and one under `lib/Service/Social/`.
- New flow node `pipelinq.competitor-watch-run` and the listener that contributes it (ADR-094, ADR-065).
- New controllers and routes for keyword proposals and targets, the Matomo report, the watch events and a manual watch run, and the connection audit.
- New occ commands `pipelinq:marketing:competitor-watch:run` and `pipelinq:marketing:connection-audit`.
- New settings: `search.crawl_source`, `search.striking_min_impressions`, `matomo.base_url`, `matomo.site_id`, `matomo.source_id`, `matomo.credential_ref`, `competitor.egress_source`, `competitor.relevance` and `competitor.user_agent`.
- Frontend: a Keywords page, a Competitors page and a Connection audit page, plus a Marketing intelligence settings section.
- Docs and an e2e spec.

## Out of scope, and why

- **GA4 and Bing Webmaster Tools.** Ruben's decision of 2026-09-04 is Search Console and Matomo first, GA4 and Bing optional. Half-building an optional connector costs the same review as building it and ships a surface nobody has credentials for. When a tenant asks, the Matomo adapter is the shape to copy.
- **DataForSEO.** It is a later bring-your-own-key source. Nothing here reads it, and `keywordTarget.volume` and `keywordTarget.difficulty` are therefore fields a person fills in, not fields a service populates.
- **LinkedIn and Meta competitor data.** No legitimate access exists for another organisation's posts. This is stated in the spec rather than left as an obvious next step, because the obvious next step here is scraping.
- **A second `searchQueryStat` schema.** The architecture document named one; the Search Console side shipped `searchQueryDaily` and its store. This change reads that schema and adds no second copy of it.
- **A `searchProperty` object.** Properties are app config today (`search.gsc.properties`) and the importer reads them from there. Moving them to objects is a migration with no new capability behind it.
- **Writing back to Matomo or Search Console.** Both connectors are read-only.

## Decisions (Ruben, 2026-09-04)

| Topic | Decision this change implements |
| --- | --- |
| Search data | Google Search Console and Matomo first; GA4 and Bing optional; DataForSEO bring-your-own-key later |
| Competitors | Lightweight, inside Pipelinq, on OpenRegister flows; no LinkedIn or Meta scraping |
| AI autonomy | Hermiq analyses and scores; it never creates a keyword target and never publishes |
| Credentials | ADR-064: the Matomo token is a broker credential reference, never a stored secret |
| Automation | ADR-094: a schedule is an OpenRegister flow, not a background job of ours |
