# Analytics connectors and the connection audit

**Spec refs**: `marketing-campaigns` (the UTM vocabulary this reads), `social-publishing` (the accounts this audits), `contact-channel-details` (the handles a client carries), ADR-064 (credential custody), ADR-067 and ADR-091 (one egress plane)
**Standards**: Matomo Reporting API (`module=API`, `format=JSON`), Matomo campaign parameters `mtm_*` and `utm_*`, Mastodon API v1, AT Protocol `app.bsky.graph.getFollows` and `getFollowers`

## ADDED Requirements

### Requirement: Matomo is read through a source, with the token as a credential reference

`MatomoReportService` MUST read Matomo's Reporting API through the OpenConnector source named by `matomo.source_id`, and MUST NOT hold, store or receive a Matomo `token_auth`. The credential MUST be identified by `matomo.credential_ref`, a brokered credential UUID whose **status** Pipelinq reads through `BrokerCredentialReader` and whose secret it can never read. The settings write MUST refuse a `matomo.credential_ref` that looks like a raw Matomo token (32 hexadecimal characters), with a message naming the broker, because pasting the token into that field is the most likely way this rule is broken and it is otherwise silent. The service MUST refuse to call when the credential's status is not `active`, reporting `relink_needed` or `not_configured` rather than letting a dead grant surface as an authentication error inside a call log.

#### Scenario: A pasted token is refused at the settings write
- **WHEN** `matomo.credential_ref` is written with a 32-character hexadecimal value through `PUT /api/settings`
- **THEN** the write is refused with a message naming the credential broker, and the stored value does not change

#### Scenario: A reference is accepted
- **WHEN** `matomo.credential_ref` is written with a UUID
- **THEN** the write succeeds and `GET /api/settings` reports it back, because a reference is not a secret

#### Scenario: An inactive credential is reported, never called

@e2e exclude the CI instance has no credential broker rows and no Matomo. Asserted by tests/Unit/Service/Matomo/MatomoReportServiceTest.php (testRefusesWhenTheCredentialIsNotActive, testReportsNotConfiguredWithoutAReference, testMakesNoCallWhenItRefuses).
- **WHEN** the referenced credential's status is `relink_needed`
- **THEN** the report answers `connected: false` with reason `relink_needed` and no call leaves the instance

### Requirement: Three Matomo reports, matched to the campaigns we already mint

`MatomoReportService` MUST read the campaign report, the referrer-type report and the goal report for a site and a window. Matomo recognises `mtm_*` and `utm_*` alike, so a campaign row MUST be matched onto the `campaign.utmCampaign` values `CampaignService` mints, and MUST NOT introduce a second campaign vocabulary. A Matomo campaign that matches no Pipelinq campaign MUST still be returned, marked unmatched, because it is usually spend outside the tool. The service MUST be read-only: no request it makes may change anything in Matomo.

#### Scenario: A campaign row is matched onto our own campaign

@e2e exclude no Matomo instance is reachable from CI. Asserted by tests/Unit/Service/Matomo/MatomoReportServiceTest.php (testMatchesACampaignRowOntoOurUtmCampaign, testKeepsAnUnmatchedRowAndMarksIt).
- **WHEN** Matomo reports a campaign whose name equals a Pipelinq campaign's `utmCampaign`
- **THEN** the row carries that campaign's id and name, and a row matching nothing is returned with `matched: false`

#### Scenario: Every request is a read

@e2e exclude same reason. Asserted by tests/Unit/Service/Matomo/MatomoReportServiceTest.php (testEveryRequestIsAGetWithAReadMethod, testRequestsCarryFormatJsonAndTheConfiguredSite).
- **WHEN** the three reports are read
- **THEN** each request is a GET carrying `module=API`, `format=JSON`, the configured `idSite` and a Matomo API method that only reads

### Requirement: GA4 and Bing Webmaster Tools are out of scope, and stay out

This change MUST NOT add a Google Analytics 4 connector and MUST NOT add a Bing Webmaster Tools connector. Both are optional per the programme's decisions, and a half-built optional connector costs a full review while shipping a surface nobody has credentials for. The Matomo adapter is the shape to copy when a tenant asks for one. DataForSEO stays out for the same reason plus one more: it is a bring-your-own-key source, and the fields it would fill (`keywordTarget.volume`, `keywordTarget.difficulty`) MUST be left unset rather than defaulted, because a zero would read as a measurement.

#### Scenario: No connector ships for a source that is out of scope

@e2e exclude the absence of a class is a repository property. Asserted by tests/Unit/Service/Matomo/MatomoReportServiceTest.php (testNoGa4OrBingOrDataForSeoConnectorShips).
- **WHEN** the services this change adds are enumerated
- **THEN** none of them names GA4, Bing Webmaster Tools or DataForSEO

### Requirement: The connection audit answers only what an API will say

`ConnectionAuditService` MUST, for every client carrying a social handle and every connected `socialAccount` on the same network, report whether we follow them and whether they follow us. Only Mastodon and Bluesky expose a follower and following list an audit can read; LinkedIn, X, Facebook, Instagram and Threads do not. For those, the answer MUST be `unknown` with a reason, and MUST NOT be `false`. The reason MUST be a per-row value, not a per-network constant, because a Mastodon instance that has hidden its lists returns `unknown` on the same path. Results MUST be stored as `socialConnection` objects so the page reads one collection instead of fanning out per client.

#### Scenario: An unanswerable network is unknown with a reason

@e2e exclude the audit needs a connected account and a reachable network, and the CI instance has neither. Asserted by tests/Unit/Service/Social/ConnectionAuditServiceTest.php (testLinkedInIsUnknownWithAReason, testNeverReportsFalseForAnUnanswerableNetwork).
- **WHEN** a client carries a LinkedIn handle and a LinkedIn account is connected
- **THEN** both directions are `unknown` and the row names why, and neither direction is `false`

#### Scenario: A hidden Mastodon list is unknown, not false

@e2e exclude same reason. Asserted by tests/Unit/Service/Social/ConnectionAuditServiceTest.php (testAHiddenMastodonListIsUnknownWithItsOwnReason).
- **WHEN** a Mastodon instance refuses the following list
- **THEN** that row is `unknown` with the instance's refusal as its reason, and other rows are unaffected

#### Scenario: A client with no handle is not audited

@e2e exclude same reason. Asserted by tests/Unit/Service/Social/ConnectionAuditServiceTest.php (testAClientWithNoHandleProducesNoRow).
- **WHEN** a client carries no social profile
- **THEN** no `socialConnection` is written for it

### Requirement: The Connection audit page reads one collection and renders the reasons

The Connection audit page MUST render one row per client and network, showing whether we follow them, whether they follow us, and the reason where the answer is unknown. It MUST read the stored `socialConnection` rows in one request and MUST NOT ask a network per client while rendering. Its empty state MUST name the accounts page, because an audit with nothing connected has nothing to say.

#### Scenario: The page renders its empty state and issues one read
- **WHEN** the Connection audit page is opened on an instance with no connected accounts
- **THEN** it renders an empty state naming the social accounts, and it issues one audit request rather than one per client

### Requirement: Marketing intelligence settings hold the sources and hold no secret

The admin settings MUST carry a Marketing intelligence section with the crawl source, the striking-distance impression floor, the Matomo base URL, site id, source id and credential reference, the competitor egress source, the relevance switch and the crawl user agent. None of these MUST be a secret: the only credential in this change is a broker reference, and the section MUST say so where the reference is entered.

#### Scenario: The section shows the fields and no secret input
- **WHEN** the Pipelinq admin settings are opened
- **THEN** the Marketing intelligence section is visible with the crawl source, the Matomo fields and the competitor egress source, and the credential reference field explains that the token lives in the broker
