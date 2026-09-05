# Keyword intelligence

**Spec refs**: `marketing-campaign-attribution` (the `searchQueryDaily` import this reads), `marketing-campaigns`, ADR-022 (apps consume OpenRegister abstractions), ADR-067 and ADR-091 (one egress plane), ADR-112 (reports are one page)
**Standards**: Google Search Console API v3 `searchanalytics/query` dimensions `date`, `query`, `page`

## ADDED Requirements

### Requirement: Queries are grouped into position buckets

`KeywordAnalysisService::positionBuckets()` MUST group the queries of a window into four buckets by their impressions-weighted mean position: `1-3`, `4-10`, `11-20` and `21+`. A boundary MUST belong to the lower bucket, so a weighted position of exactly 3.0 is `1-3`, exactly 10.0 is `4-10` and exactly 20.0 is `11-20`. A query with zero impressions has no position and MUST be counted in no bucket. Each bucket MUST report the number of queries, their summed clicks and their summed impressions. The method MUST be pure: it takes rows and returns buckets, resolving no service and reading no configuration.

#### Scenario: A boundary position falls in the lower bucket

@e2e exclude the derivation runs over rows an instance without a Google credential does not have, and the boundary is an arithmetic property no browser can observe. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testPositionExactlyThreeIsInTheTopBucket, testPositionExactlyTenIsInTheSecondBucket, testPositionExactlyTwentyIsInTheThirdBucket, testPositionJustAboveTwentyIsInTheTail).
- **WHEN** three queries have weighted positions of exactly 3.0, 10.0 and 20.0
- **THEN** they land in `1-3`, `4-10` and `11-20` respectively, and a query at 20.1 lands in `21+`

#### Scenario: A query with no impressions has no position

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testAQueryWithNoImpressionsIsInNoBucket).
- **WHEN** a row carries zero impressions
- **THEN** its query appears in no bucket and the bucket counts do not include it

#### Scenario: The buckets are shown on the Keywords page
- **WHEN** the Keywords page is opened on an instance with no imported rows
- **THEN** the page renders its empty state naming Search Console, and no bucket claims a count

### Requirement: Striking-distance queries are queries one push from page one

`KeywordAnalysisService::strikingDistance()` MUST return the queries whose impressions over the window are at or above a floor (default 100), whose impressions-weighted position lies between 8.0 and 20.0 inclusive, and whose click-through rate is strictly below the rate that position normally earns. The expected rate MUST come from `ExpectedCtrCurve`, a documented constant of click-through by integer position, linearly interpolated between two integer positions and flat beyond position 20. Each result MUST carry the query, its clicks, impressions, click-through, position, the expected click-through and the shortfall between them, ordered by impressions descending. The floor MUST be an argument with a default, so a small site can lower it.

#### Scenario: A query at the impression floor qualifies and one below it does not

@e2e exclude the predicate is arithmetic over imported rows the CI instance cannot obtain. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testAQueryAtTheImpressionFloorQualifies, testAQueryBelowTheImpressionFloorIsIgnored).
- **WHEN** two queries sit at position 12 with the same click-through, one with exactly the floor in impressions and one with one impression fewer
- **THEN** the first is returned and the second is not

#### Scenario: Both ends of the position band are inclusive

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testPositionEightIsInsideTheBand, testPositionTwentyIsInsideTheBand, testPositionJustOutsideTheBandIsIgnored).
- **WHEN** queries sit at positions 7.9, 8.0, 20.0 and 20.1 with identical volumes
- **THEN** the ones at 8.0 and 20.0 are returned and the ones at 7.9 and 20.1 are not

#### Scenario: A query already earning its position is not a finding

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testAQueryAtOrAboveTheExpectedRateIsNotAFinding).
- **WHEN** a query at position 10 has a click-through at or above the curve's value for position 10
- **THEN** it is not returned, and one with a lower click-through at the same position is

#### Scenario: The expected rate is interpolated between integer positions

@e2e exclude the curve is a pure lookup. Asserted by tests/Unit/Service/Search/ExpectedCtrCurveTest.php (testInterpolatesBetweenTwoIntegerPositions, testIsFlatBeyondPositionTwenty, testAPositionBelowOneUsesTheFirstValue).
- **WHEN** the curve is asked for position 10.5
- **THEN** it answers the midpoint of its values for 10 and 11, and any position above 20 answers the tail value

### Requirement: Cannibalisation names two pages competing for one query

`KeywordAnalysisService::cannibalisation()` MUST return the queries where two or more pages carry impressions in the window and the combined click-through is materially below the better page's own rate. "Materially" MUST mean at or below the better page's rate times one minus a margin (default 0.10). A page whose share of the query's impressions is below a minimum (default 20 percent) MUST NOT make a query a finding, so one stray page cannot create one. The query's total impressions MUST reach the same floor the striking-distance derivation uses. Each finding MUST name the query, every contributing page with its clicks, impressions, click-through and position, and which page is the better one. Both guards MUST be arguments with defaults.

#### Scenario: A stray second page is not cannibalisation

@e2e exclude the predicate is arithmetic over imported rows. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testASecondPageBelowTheShareFloorIsNotAFinding, testASecondPageAtTheShareFloorIsAFinding).
- **WHEN** one page holds 95 percent of a query's impressions and a second holds 5 percent
- **THEN** the query is not returned, and it is returned once the second page reaches the share floor

#### Scenario: An immaterial loss is not a finding

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testALossInsideTheMarginIsNotAFinding, testALossAtTheMarginIsAFinding).
- **WHEN** the combined click-through is one percent below the better page's rate and the margin is ten percent
- **THEN** the query is not returned, and it is returned once the loss reaches the margin

#### Scenario: A single-page query is never cannibalisation

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testASinglePageQueryIsNeverAFinding).
- **WHEN** every row for a query names the same page
- **THEN** the query is not returned whatever its click-through

### Requirement: A content gap is a query no page of ours answers

`KeywordAnalysisService::contentGaps()` MUST return the queries whose impressions reach the floor and whose terms are carried by no crawled page. A page carries a query when, after normalising both sides (lowercase, diacritics stripped, whitespace collapsed) and dropping tokens shorter than three characters and a Dutch and English stop-word list, **every** remaining token of the query appears in that page's title-and-headings text. When no page has been crawled the method MUST return no gaps and the caller MUST report that the crawl did not run, rather than presenting an empty list as "no gaps".

#### Scenario: Every token must be carried, not any

@e2e exclude the detector runs over imported rows and a crawl, neither of which a bare instance has. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testAPageCarryingOnlySomeTokensIsAGap, testAPageCarryingEveryTokenIsNotAGap).
- **WHEN** a query is "woo verzoek indienen" and the only page's title is "Verzoek indienen"
- **THEN** the query is a gap, and it stops being one once a page's headings carry all three terms

#### Scenario: Short tokens and stop words do not decide a gap

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/KeywordAnalysisServiceTest.php (testStopWordsAndShortTokensAreIgnored).
- **WHEN** a query is "hoe kan ik een woo verzoek indienen" and a page's headings carry "woo verzoek indienen"
- **THEN** the query is not a gap

#### Scenario: No crawl reports itself as no crawl
- **WHEN** `search.crawl_source` is empty and `GET /api/marketing/keyword-proposals` is read
- **THEN** the response says `crawl.crawled` is `false` with reason `not_configured`, `gaps` is empty, and the Keywords page says the gap check did not run

### Requirement: Our own pages are crawled through the egress plane

`SiteContentCrawler` MUST fetch the pages that appear in the window's `searchQueryDaily` rows, through the OpenConnector source named by `search.crawl_source`, and MUST keep each page's `<title>` and its `h1` to `h3` headings. It MUST construct no HTTP client of its own. It MUST cap the number of pages it fetches per run (default 50, highest impressions first) so a large property cannot make one request unbounded. When no source is configured, when OpenConnector is absent, or when the source refuses, the crawler MUST return no pages together with the reason, and MUST NOT report that as a successful empty crawl.

#### Scenario: Nothing is fetched without a configured source

@e2e exclude the crawler needs an OpenConnector source, and the CI instance installs only openregister and planninq alongside pipelinq. Asserted by tests/Unit/Service/Search/SiteContentCrawlerTest.php (testReturnsNotConfiguredWithoutASource, testConstructsNoHttpClient).
- **WHEN** `search.crawl_source` is empty
- **THEN** the crawler returns no pages with reason `not_configured` and makes no call

#### Scenario: The most-seen pages are crawled first and the run is capped

@e2e exclude same reason. Asserted by tests/Unit/Service/Search/SiteContentCrawlerTest.php (testCrawlsTheHighestImpressionPagesFirst, testStopsAtTheCap).
- **WHEN** 80 distinct pages appear in the window and the cap is 50
- **THEN** the 50 pages with the most impressions are fetched, in that order, and no more

#### Scenario: Title and headings are extracted, scripts and styles are not

@e2e exclude the extraction is a pure parse. Asserted by tests/Unit/Service/Search/HtmlTextExtractorTest.php (testExtractsTitleAndHeadings, testIgnoresScriptAndStyleContent, testDecodesEntities).
- **WHEN** a fetched document carries a title, two headings, a script block and an HTML entity
- **THEN** the extracted text holds the title and both headings, holds neither the script nor the style content, and the entity is decoded

### Requirement: A proposal becomes a keyword target only when a person confirms it

`GET /api/marketing/keyword-proposals` MUST return the buckets, the striking-distance queries, the cannibalisation findings and the content gaps for a window, together with the crawl status. It MUST create nothing. `POST /api/marketing/keyword-targets` MUST be the only path that creates a `keywordTarget`, MUST record which proposal kind produced it, and MUST require the same CRM privilege check the other marketing endpoints apply. `keywordTarget.volume` and `keywordTarget.difficulty` MUST be left unset by every path in this change, because the source that would fill them is out of scope and a zero would read as a measurement.

#### Scenario: Reading proposals creates nothing
- **WHEN** `GET /api/marketing/keyword-proposals` is read twice on an instance with no keyword targets
- **THEN** both responses carry proposals only, and the `keywordTarget` collection is still empty

#### Scenario: Confirming a proposal creates one target with its provenance
- **WHEN** a proposal is confirmed through `POST /api/marketing/keyword-targets` with a term, an intent and a status
- **THEN** one `keywordTarget` exists carrying that term, the status, the proposal kind that produced it, and no `volume` or `difficulty`

#### Scenario: An unprivileged session is refused
- **WHEN** a session without the CRM privilege posts a keyword target
- **THEN** the response is 403 and no object is created

### Requirement: The Keywords page shows the four derivations and confirms one at a time

The Keywords page MUST render, for the selected window, the position buckets, the striking-distance list, the cannibalisation list and the content gaps, each with its own empty state saying why it is empty. Every row that can become a target MUST offer a confirm action that opens a dialog carrying the term, and confirming MUST post exactly one `keywordTarget`. The page MUST NOT fan out one request per proposal before it renders: one read serves the page.

#### Scenario: The page renders its empty state before anything is imported
- **WHEN** the Keywords page is opened on an instance with no imported rows
- **THEN** it renders an empty state naming Search Console and the Marketing intelligence settings, and it issues one proposals request, not one per query
