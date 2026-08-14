# xwiki-proxy Specification

## Purpose
TBD - created by archiving change pipelinq-xwiki-through-or. Update Purpose after archive.
## Requirements
### Requirement: xWiki search prefers OpenRegister with legacy fallback

The xWiki proxy search MUST prefer OpenRegister's OpenConnector-routed xWiki
search endpoint (`GET /apps/openregister/api/integrations/xwiki/search`,
ADR-022) so the consuming app does not hold a direct xWiki URL. When the OR
`xwiki` source is not usable — OR responds with a 503 (dormant source /
upstream down) or OpenRegister is absent — the proxy MUST fall back to the
existing direct path (the `OCA\Xwiki` `SettingsManager` base URL, else the
admin-configured `xwiki_direct_url`), so envs already configured for xWiki keep
working unchanged. The result envelope (`results`, `total`, `limit`, `offset`)
and the space/tags client-side filter MUST be identical regardless of which path
produced the rows.

**Feature tier**: V1

#### Scenario: OR endpoint returns results
@e2e exclude transport-leg choice, not observable in the UI: XWikiServiceTest::testSearchPrefersOpenRegisterOnSuccess proves the OR leg was taken by leaving the direct URL empty, and asserts the mapped {id,title,space,url,tags} shape. tests/e2e/xwiki-integration.spec.ts renders the widget but cannot distinguish which leg served it.

- GIVEN the OpenRegister `xwiki` source is configured and enabled
- WHEN the proxy receives `GET /api/xwiki/search?q=paspoort`
- THEN the proxy MUST call `GET /apps/openregister/api/integrations/xwiki/search?q=paspoort` server-side
- AND on HTTP 200 map each OR row to the widget shape `{id, title, space, modified, url, tags}`
- AND return `{results, total, limit, offset}` without contacting xWiki directly

#### Scenario: OR source dormant or down falls back to the direct path
@e2e exclude transport-leg choice, not observable in the UI: XWikiServiceTest::testSearchFallsBackToDirectPathWhenOrUnavailable and ::testSearchFallsBackOnNon200 assert the fallback BRANCH is taken. NOT asserted anywhere: that a CONFIGURED direct URL is then queried at /rest/wikis/query — that is an unwritten PHPUnit case, not an e2e gap.

- GIVEN the OpenRegister `xwiki` source is dormant (returns 503) and a direct xWiki URL is configured
- WHEN the proxy receives `GET /api/xwiki/search?q=paspoort`
- THEN the proxy MUST fall back to the legacy direct path (`SettingsManager` / `xwiki_direct_url`)
- AND query xWiki's `/rest/wikis/query` exactly as before the re-point
- AND return the same `{results, total, limit, offset}` envelope

#### Scenario: Neither OR nor a direct URL is available
@e2e exclude unconfigured-service behaviour: XWikiServiceTest::testSearchUnconfiguredReturnsEmpty asserts the empty envelope, total=0, limit preserved and no fatal. An empty widget in the browser cannot distinguish "unconfigured" from "no results".

- GIVEN OpenRegister returns 503 (or is absent) AND no direct xWiki URL is configured
- WHEN the proxy receives `GET /api/xwiki/search?q=paspoort`
- THEN the proxy MUST return `{results: [], total: 0, limit, offset}` with HTTP 200
- AND MUST NOT surface a fatal error or the upstream 503 to the consumer

#### Scenario: Space filter applies to OR-sourced results
@e2e exclude server-side filter over an OR-sourced list: XWikiServiceTest::testSearchViaOpenRegisterAppliesSpaceFilter seeds two rows across Kennisbank/Other and asserts exactly one survives. The rendered widget shows the post-filter list either way.

- GIVEN the OpenRegister `xwiki` source returns pages across spaces "Kennisbank" and "Other"
- WHEN the proxy receives `GET /api/xwiki/search?q=klacht&space=Kennisbank`
- THEN the proxy MUST return only results whose `space` equals "Kennisbank"

