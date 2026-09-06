# Competitor watches

**Spec refs**: `social-publishing` (the accounts phase 3 connected), ADR-065 (the flow engine), ADR-094 (new automation targets the flow engine, not n8n), ADR-067 and ADR-091 (one egress plane), ADR-088 (agent-authored artefacts are marked)
**Standards**: RSS 2.0, Atom (RFC 4287), the sitemaps protocol 0.9, Mastodon API v1, AT Protocol `app.bsky.feed.getAuthorFeed`

## ADDED Requirements

### Requirement: A watch runs on an OpenRegister flow schedule and never on a job of ours

Competitor watches MUST be driven by an OpenRegister flow: a `openregister.trigger-schedule` node into `pipelinq.competitor-watch-run` into `openregister.end`, shipped declaratively as `x-openregister-flows` on the `competitorWatch` schema. This change MUST add no `TimedJob` and no `QueuedJob` for watching. The flow MUST arrive disabled, per the engine's adoption contract, and its schedule node MUST carry an explicit `runAs`, because `TriggerScheduleNode` resolves that against the user manager and offers no owner fallback. `competitorWatch.schedule` stays on the object and decides which watches are due on a given firing, so one flow drives watches of different cadences.

#### Scenario: The shipped flow is a schedule into our node

@e2e exclude a flow definition is register configuration, not a browser surface, and the CI instance imports it without running it. Asserted by tests/Unit/Settings/CompetitorWatchFlowTest.php (testTheShippedFlowTriggersOnAScheduleAndCallsOurNode, testTheScheduleNodeCarriesAnExplicitRunAs, testTheFlowArrivesDisabled).
- **WHEN** the `competitorWatch` schema fragment is read
- **THEN** it declares one flow whose trigger is `openregister.trigger-schedule` with a `runAs`, whose next node is `pipelinq.competitor-watch-run`, and whose `enabled` is false

#### Scenario: No background job is added for watching

@e2e exclude the absence of a class is a repository property. Asserted by tests/Unit/Settings/CompetitorWatchFlowTest.php (testNoTimedJobDrivesCompetitorWatches).
- **WHEN** `lib/BackgroundJob/` is enumerated
- **THEN** no job references a competitor watch

#### Scenario: The node does not scope itself, because the dispatcher already does

@e2e exclude the node executes inside the engine, which the CI instance does not fire. Asserted by tests/Unit/Flow/CompetitorWatchRunNodeTest.php (testDoesNotDeclareItselfSelfScoped, testRunsOnlyTheWatchesThatAreDue, testOneFailingWatchDoesNotStopTheRun).
- **WHEN** the node executes
- **THEN** it runs only the watches whose schedule is due, it does not wrap itself in a run-as scope (the dispatcher wraps every contributed node in the run's acting identity, and self-wrapping double-scopes), and a watch that fails leaves the rest of the run intact

### Requirement: Five watch kinds, and the two that are excluded are named

A `competitorWatch.kind` MUST be one of `rss`, `sitemap`, `page`, `fediverse` or `search`. LinkedIn and Meta post data MUST NOT be read by any watch: no legitimate machine access to another organisation's posts exists on either network, and the exclusion is part of this specification rather than an unfilled gap. `search` MUST run through hermiq's web search and MUST return nothing, without failing the run, when hermiq is absent or its search provider is unconfigured.

#### Scenario: The schema admits exactly the five kinds

@e2e exclude schema enums are register configuration. Asserted by tests/Unit/Settings/CompetitorWatchFlowTest.php (testTheKindEnumIsExactlyTheFiveSupportedKinds).
- **WHEN** the `competitorWatch` schema is read
- **THEN** its `kind` enum is exactly `rss`, `sitemap`, `page`, `fediverse` and `search`, and names no network whose data cannot be obtained legitimately

#### Scenario: A search watch without hermiq is a quiet no-op

@e2e exclude hermiq is not installed on the CI instance. Asserted by tests/Unit/Service/Competitor/SearchWatchReaderTest.php (testReturnsNothingWithoutHermiq, testDoesNotFailTheRun).
- **WHEN** a `search` watch runs on an instance without hermiq
- **THEN** it produces no events, reports reason `not_configured`, and the other watches of the same run still complete

### Requirement: A feed watch reports the entries it has not seen

`FeedWatchReader::parse()` MUST parse both RSS 2.0 (`<item>`) and Atom (`<entry>`) and return each entry's title, link and published instant. It MUST tolerate a missing published date by falling back to the moment of reading. A document that is not XML MUST be reported as `unparsable`, not as an empty feed. Fetching MUST go through the egress seam; the parse MUST be a pure function over a document string.

#### Scenario: RSS and Atom both parse

@e2e exclude parsing is pure and no feed is reachable from the CI instance. Asserted by tests/Unit/Service/Competitor/FeedWatchReaderTest.php (testParsesRssItems, testParsesAtomEntries, testFallsBackToNowWhenThereIsNoDate).
- **WHEN** an RSS document with two items and an Atom document with two entries are parsed
- **THEN** both yield two entries carrying title, link and published instant

#### Scenario: A non-XML body is unparsable, not empty

@e2e exclude same reason. Asserted by tests/Unit/Service/Competitor/FeedWatchReaderTest.php (testANonXmlBodyIsUnparsable).
- **WHEN** an HTML error page is parsed as a feed
- **THEN** the result is `unparsable` and no entries are claimed

### Requirement: A sitemap watch reports new and changed locations

`SitemapWatchReader::diff()` MUST take the previous and the current sitemap state and return the `<loc>` entries that are new and the ones whose `<lastmod>` changed, as two separate lists. A location present in both with an unchanged `lastmod` MUST appear in neither. A location that carries no `lastmod` MUST be treated as unchanged once it has been seen, so a sitemap without dates does not report its whole contents on every run. A sitemap index MUST be recognised and its child sitemaps followed, up to a documented depth.

#### Scenario: New and changed are told apart

@e2e exclude the diff is pure and no sitemap is reachable from the CI instance. Asserted by tests/Unit/Service/Competitor/SitemapWatchReaderTest.php (testReportsNewLocations, testReportsChangedLastmod, testAnUnchangedLocationIsInNeitherList).
- **WHEN** a sitemap gains one location and changes the `lastmod` of another
- **THEN** the first is reported as new, the second as changed, and the untouched ones as neither

#### Scenario: A sitemap without dates does not re-report itself

@e2e exclude same reason. Asserted by tests/Unit/Service/Competitor/SitemapWatchReaderTest.php (testALocationWithoutLastmodIsUnchangedOnceSeen).
- **WHEN** the same dateless sitemap is read twice
- **THEN** the second read reports nothing

### Requirement: A page watch diffs a selected fragment and stores a fingerprint

A `page` watch MUST take a CSS selector and compare only the fragment it selects. `PageWatchReader::diff()` MUST return whether the fragment changed and a short summary. The watch MUST store **fingerprints** of the fragment and of its lines, never the fragment itself, so Pipelinq does not become a copy of somebody else's page. The summary MUST therefore quote only lines that were ADDED, which come from the run's own fresh fetch, and MUST report removals as a count rather than as text, because the previous text is deliberately not kept. A selector that matches nothing MUST report `unparsable` with the selector in the reason, not silently report "no change" forever.

#### Scenario: Only the selected fragment decides a change

@e2e exclude the diff is pure and no page is reachable from the CI instance. Asserted by tests/Unit/Service/Competitor/PageWatchReaderTest.php (testAChangeOutsideTheSelectorIsNotAChange, testAChangeInsideTheSelectorIsAChange).
- **WHEN** a document changes outside the selected fragment
- **THEN** the watch reports no change, and it reports one when the fragment itself changes

#### Scenario: A selector that matches nothing says so

@e2e exclude same reason. Asserted by tests/Unit/Service/Competitor/PageWatchReaderTest.php (testASelectorThatMatchesNothingIsUnparsable).
- **WHEN** the selector matches no element
- **THEN** the result is `unparsable` and names the selector

#### Scenario: The stored state is fingerprints, and removals are counted rather than quoted

@e2e exclude the stored value is inspected in the unit suite. Asserted by tests/Unit/Service/Competitor/PageWatchReaderTest.php (testStoresFingerprintsAndNotTheFragment, testQuotesAddedLinesAndCountsRemovedOnes).
- **WHEN** a page watch runs against a fragment carrying a distinctive sentence, and a later run removes it
- **THEN** the stored state never contains that sentence, the summary quotes only the lines the fresh fetch added, and the removed one appears as a count

### Requirement: A watch event is written once per watch and URL

`WatchEventStore` MUST upsert a `watchEvent` per (watch, url), so a re-run over the same window changes nothing and a schedule that fires twice cannot duplicate a headline. Each event MUST carry its competitor, its watch, the kind, a title, a URL, a summary of what changed and when it was seen.

#### Scenario: A second run over the same window writes nothing new
- **WHEN** the same watch is run twice through `POST /api/marketing/competitor-watches/{id}/run` with nothing changed upstream
- **THEN** the number of `watchEvent` objects for that watch is the same after the second run as after the first

### Requirement: Relevance is scored by hermiq or left unscored

`RelevanceScorer` MUST ask hermiq to score an event from 0 to 100 when `competitor.relevance` is on and hermiq is available. When hermiq is absent, its provider is unconfigured, or its answer is not a number in range, the event MUST be stored **without** a `relevanceScore`. It MUST NOT be stored with a score of zero, because an unscored event and an irrelevant one would then sort together and the unscored ones would never be read. Scoring MUST be off by default, because it sends a competitor's headline to the configured model. A scored event MUST be marked as agent-authored per ADR-088.

#### Scenario: No hermiq means no score, not a zero

@e2e exclude hermiq is not installed on the CI instance. Asserted by tests/Unit/Service/Competitor/RelevanceScorerTest.php (testLeavesTheEventUnscoredWithoutHermiq, testNeverWritesZeroAsADegradedScore).
- **WHEN** an event is scored on an instance without hermiq
- **THEN** the event carries no `relevanceScore` key at all

#### Scenario: An unparsable answer is also unscored

@e2e exclude same reason. Asserted by tests/Unit/Service/Competitor/RelevanceScorerTest.php (testAnAnswerOutsideTheRangeIsUnscored, testANonNumericAnswerIsUnscored).
- **WHEN** hermiq answers "very relevant" or "180"
- **THEN** the event is stored unscored

#### Scenario: Scoring is off until an admin turns it on

@e2e exclude the setting gates a call the CI instance cannot make. Asserted by tests/Unit/Service/Competitor/RelevanceScorerTest.php (testDoesNotCallHermiqWhenTheSettingIsOff).
- **WHEN** `competitor.relevance` is unset
- **THEN** nothing is sent to hermiq

### Requirement: Every outbound read leaves through an OpenConnector source

`ConnectorEgress` MUST be the only outbound path in this change. Every reader (crawl, Matomo, feed, sitemap, page, fediverse) MUST call through it, and no class in this change may construct an `IClientService`. It MUST resolve the `Source` object from register `openconnector`, schema `source`, and hand the request to `CallService::call()`. Its result MUST distinguish `not_configured`, `unavailable`, `refused` and `unparsable`, so a caller can never present a failure as an empty result.

#### Scenario: No HTTP client is constructed anywhere in this change

@e2e exclude the absence of a dependency is a repository property. Asserted by tests/Unit/Service/Competitor/ConnectorEgressTest.php (testNoServiceInThisChangeInjectsAnHttpClient).
- **WHEN** the services this change adds are enumerated
- **THEN** none of them declares `IClientService` as a dependency

#### Scenario: Absent OpenConnector is unavailable, not empty

@e2e exclude the CI instance installs neither OpenConnector nor a source. Asserted by tests/Unit/Service/Competitor/ConnectorEgressTest.php (testReportsUnavailableWithoutOpenConnector, testReportsNotConfiguredWithoutASourceId, testReportsRefusedOnANonTwoHundredStatus).
- **WHEN** a read is attempted with OpenConnector absent
- **THEN** the result is `unavailable`, and a missing source id is `not_configured`, and a non-2xx answer is `refused`

### Requirement: The Competitors page shows what changed and says what did not run

The Competitors page MUST list the watch events over a window, newest first, showing the competitor, the kind, the title, the link and the relevance where one exists and "not scored" where none does. It MUST offer a run action per watch and MUST show, per watch, when it last ran and what its last outcome was. When no egress source is configured the page MUST say so rather than rendering an empty list.

#### Scenario: The page renders its empty state and names the setting
- **WHEN** the Competitors page is opened on an instance with no configured egress source
- **THEN** it says so and names the Marketing intelligence settings, rather than rendering an empty event list that reads as "they published nothing"
