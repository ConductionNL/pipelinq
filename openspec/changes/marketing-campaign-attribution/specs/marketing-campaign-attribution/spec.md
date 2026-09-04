# Marketing campaign attribution and Search Console

**Spec refs**: `marketing-email-tracking` (phase 0 dual-write), `marketing-analytics`, `marketing-blast`, the fleet traffic analytics contract (sections 1, 3 and 6), ADR-022 (apps consume OR abstractions)
**Standards**: GA4 `utm_*` campaign parameters; Google Search Console API v3 `searchanalytics/query`; RFC 7523 JWT bearer assertion for the service account

## ADDED Requirements

### Requirement: Blast links carry campaign parameters

Every `<a href>` in a blast body MUST be decorated with `utm_source=email`, `utm_medium=email`, `utm_campaign=<campaign>` and `utm_content=<blast id>` when the link does not already carry that parameter. A parameter the author wrote MUST be kept as written. The unsubscribe link and any link still carrying a merge tag (`{{`), in-page anchors (`#`), and `mailto:`, `tel:` and `sms:` links MUST NOT be touched. The campaign value is the blast name as a slug, falling back to the template name and then to the blast id, so it is safe in a query string. The decoration MUST run before the first-party click redirect wraps the link, so the redirect target carries the parameters and Portaliq's collector parses them into `campaign`, `source`, `medium` and `content`. The per-tenant setting `blast.utm_auto` (default on) turns the decoration off.

#### Scenario: Absent parameters are added

@e2e exclude decoration happens at send time inside the mail body handed to openconnector, which the CI instance does not install (`.github/workflows/code-quality.yml` pins `additional-apps` to openregister and planninq), so the decorated body never exists in a browser run. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testAddsAllFourParametersToABareLink, testAppendsToAnExistingQueryString) and tests/Unit/Service/BlastServiceTest.php (testDispatchDecoratesTheTemplateBodyWithCampaignParameters).
- **WHEN** a blast body link has no `utm_*` parameters
- **THEN** the sent link carries `utm_source=email`, `utm_medium=email`, `utm_campaign=<slug>` and `utm_content=<blast id>`

#### Scenario: Author-written parameters are kept

@e2e exclude same reason: the decorated body is only observable in the send path. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testKeepsParametersTheAuthorWrote).
- **WHEN** a link already carries `utm_campaign=spring`
- **THEN** `utm_campaign` stays `spring` and only the missing parameters are appended

#### Scenario: Unsubscribe, merge-tag, anchor and mailto links are untouched

@e2e exclude same reason. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testLeavesUnsubscribeMergeTagUntouched, testSkipsMailtoTelAndAnchors).
- **WHEN** the body contains `{{unsubscribe_link}}`, `href="#top"`, `href="mailto:..."` or `href="tel:..."`
- **THEN** those anchors are returned byte for byte

#### Scenario: Parameters precede the click redirect

@e2e exclude the click token is an HMAC a browser cannot mint. Asserted by tests/Unit/Service/TrackingLinkServiceTest.php (testInjectTrackingWrapsADecoratedUrlSoTheRedirectTargetCarriesUtm).
- **WHEN** first-party tracking is on and a decorated body is passed to `injectTracking()`
- **THEN** the signed click token's target URL carries the `utm_*` parameters

#### Scenario: The setting turns decoration off

@e2e exclude same reason as the first scenario. Asserted by tests/Unit/Service/CampaignLinkDecoratorTest.php (testDoesNothingWhenTheSettingIsOff).
- **WHEN** `blast.utm_auto` is `false`
- **THEN** the body is returned unchanged

#### Scenario: The campaign value derives from the blast name
- **WHEN** a blast named "Spring newsletter 2026" is read through `GET /api/blasts/{id}/performance`
- **THEN** the response's `campaign` is `spring-newsletter-2026`

### Requirement: Campaign performance joins site sessions to a blast

`CampaignPerformanceService::forBlast()` MUST return the blast's own opens, clicks and attributed deals, and, when `blast.traffic_portal` names a portal and Portaliq is installed, the site sessions Portaliq attributed to the blast's campaign. It reads `portalTrafficDaily` objects (register `portaliq`, schema `portalTrafficDaily`) for that portal and window through OpenRegister's object service, duck-typed and with `_rbac` and `_multitenancy` off, and sums the `campaigns[]` rows whose `campaign` equals the blast's campaign slug or the blast name (the value phase 0 stamps on email events). Per-campaign page views and form submits are `null` until the rollup carries them. When no portal is configured or Portaliq is not installed the response says `connected: false` with a reason and the site block is `null`; the blast's own numbers are still returned.

#### Scenario: No portal configured reports not connected
- **WHEN** `blast.traffic_portal` is empty and the blast performance page's Attribution tab opens
- **THEN** the "Site traffic from this campaign" block says "Not connected to a portal" and `GET /api/blasts/{id}/performance` returns `connected: false`

#### Scenario: Portaliq absent reports not connected

@e2e exclude with a portal configured and Portaliq absent the page shows the same block as the previous scenario; the distinguishing `reason` is only in the JSON and is asserted by tests/Unit/Service/CampaignPerformanceServiceTest.php (testReportsPortaliqMissingWhenTheAppIsNotInstalled).
- **WHEN** `blast.traffic_portal` names a portal but Portaliq is not installed
- **THEN** `connected` is `false` with reason `portaliq_missing` and nothing is read

#### Scenario: Sessions are summed from the daily rollups for the campaign

@e2e exclude the CI instance does not install Portaliq, so no rollup exists to read. Asserted by tests/Unit/Service/CampaignPerformanceServiceTest.php (testSumsSessionsOfMatchingCampaignRowsAcrossDays, testMatchesTheBlastNameThatEmailEventsCarry, testIgnoresRowsOfOtherCampaigns).
- **WHEN** three daily rollups carry `campaigns[]` rows for the blast's campaign
- **THEN** `site.sessions` is their sum, `site.days` is 3, and rows of other campaigns are ignored

### Requirement: Search Console queries are imported with a service account

A daily `SearchConsoleImportJob` MUST pull the last three days of `searchanalytics/query` rows (dimensions `date`, `query`, `page`) for every property in `search.gsc.properties`, authenticating with the service account key in `search.gsc.service_account_key`: an RS256 JWT assertion signed with `openssl_sign` (claims `iss` = client email, `scope` = `https://www.googleapis.com/auth/webmasters.readonly`, `aud` = the key's token URI, `iat`, `exp` = `iat` + 3600) exchanged for an access token, all through `IClientService`. Rows MUST be upserted as `searchQueryDaily` objects keyed by (property, date, query, page) so a re-run changes nothing. The key MUST be stored as a sensitive app-config value and MUST NOT be returned by any read path; `GET /api/settings` reports only whether one is set. `occ pipelinq:marketing:search-console:import` runs the same pass on demand. Nothing is imported without a key or without properties.

#### Scenario: The assertion is signed RS256 with the required claims

@e2e exclude a JWT is a string handed to Google; nothing renders it. Asserted by tests/Unit/Service/SearchConsole/GoogleServiceAccountAuthTest.php (testAssertionCarriesTheClaimsAndVerifiesWithThePublicKey, testTokenExchangePostsTheAssertion).
- **WHEN** an assertion is built for a key at time `t`
- **THEN** its header is `{alg: RS256, typ: JWT}`, its claims are `iss`, `scope`, `aud`, `iat` = `t`, `exp` = `t` + 3600, and the signature verifies with the key's public half

#### Scenario: Rows are upserted per property, date, query and page

@e2e exclude the rig cannot reach Google, and the import runs from cron or occ, not a browser. Asserted by tests/Unit/Service/SearchConsole/SearchConsoleImportServiceTest.php (testImportsRowsAsSearchQueryDailyObjects, testARerunUpdatesInsteadOfDuplicating, testPaginatesUntilAShortPage).
- **WHEN** the same day is imported twice
- **THEN** the second run updates the existing objects and creates none

#### Scenario: Nothing is imported without a key or properties

@e2e exclude same reason. Asserted by tests/Unit/Service/SearchConsole/SearchConsoleImportServiceTest.php (testSkipsWithoutAKey, testSkipsWithoutProperties).
- **WHEN** the key or the property list is empty
- **THEN** no HTTP request is made and the result reports zero properties

#### Scenario: The key is never echoed back
- **WHEN** an admin saves a service account key through `PUT /api/settings`
- **THEN** the response and every later `GET /api/settings` carry `search.gsc.service_account_key_set` = `true` and no `search.gsc.service_account_key`

### Requirement: Marketing traffic settings

The Pipelinq admin settings page MUST show a "Marketing traffic" section with the `blast.utm_auto` switch, the `blast.traffic_portal` slug, the Search Console property list and a field to paste a service account key, and MUST show which service account email is on file so the admin knows what to add on the property.

#### Scenario: Admin settings show the UTM toggle and Search Console fields
- **WHEN** an admin opens `/settings/admin/pipelinq`
- **THEN** the section shows the campaign parameters switch, the portal slug, the properties field and the key field

### Requirement: Search queries page lists top queries

`GET /api/marketing/search-queries` MUST aggregate `searchQueryDaily` rows over a window (default the last 28 days) per query: clicks and impressions summed, CTR recomputed, position as the impressions-weighted mean, ordered by clicks. The "Search queries" page under Marketing MUST render those rows and an empty state that points to the settings when no data exists.

#### Scenario: Empty state without data
- **WHEN** no `searchQueryDaily` object exists and the Search queries page opens
- **THEN** it shows the empty state naming Search Console and the settings

#### Scenario: Top queries aggregate clicks over the window

@e2e exclude a populated table needs imported rows the rig cannot fetch from Google; seeding them through OpenRegister would test the object API, not the import. Asserted by tests/Unit/Service/SearchConsole/SearchQueryReportServiceTest.php (testAggregatesPerQueryAcrossDaysAndPages, testPositionIsImpressionWeighted, testOrdersByClicksAndHonoursTheLimit).
- **WHEN** a query appears on three days and two pages
- **THEN** one row sums its clicks and impressions and reports the weighted position
