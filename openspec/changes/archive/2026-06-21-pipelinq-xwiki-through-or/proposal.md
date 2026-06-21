---
kind: code
references: [ADR-022]
---

## Why

The xWiki knowledge-base proxy in pipelinq (`XWikiService`) holds a direct xWiki
URL — it resolves the base URL from the optional `OCA\Xwiki` NC app's
`SettingsManager`, falling back to the admin-configured `xwiki_direct_url`. Per
ADR-022 (apps consume OR abstractions, never hold integration endpoints/creds
themselves), the knowledge-base seam should run through OpenRegister's
OpenConnector-routed xWiki leaf instead.

The OR groundwork has landed: OR exposes
`GET /apps/openregister/api/integrations/xwiki/search?q=&limit=&offset=`
(`XwikiLinkService::searchPages` → `XwikiProvider` → OpenConnector `xwiki`
source), returning `{results,total,limit,offset}` on success or
`503 {error,details:{cause}}` (cause ∈ `openconnector-down` /
`openconnector-source-missing` / `provider-auth` / `upstream-service-down`).

**The constraint:** the OpenConnector `xwiki` source is currently **dormant**
(`isEnabled=false`, placeholder URL). A naive re-point would REGRESS configured
envs — OR returns 503 until an operator configures + enables the source, but
today the pipelinq widget WORKS in a configured env via `SettingsManager` /
`xwiki_direct_url`.

## What Changes

**Safe-partial re-point** (not a full cut-over). `XWikiService::search()`:

- PREFERS OR's search endpoint: an internal server-side
  `GET /apps/openregister/api/integrations/xwiki/search` (via `IURLGenerator` +
  `IClientService`, mirroring `NoteEventService::fetchEntityData`). On HTTP 200
  the OR rows are mapped to the widget shape (`{id,title,space,modified,url,tags}`)
  and returned through the shared `finishSearch()` filter/cache helper.
- FALLS BACK to the existing `SettingsManager` / `xwiki_direct_url` path when OR
  is not usable yet — i.e. OR returns 503 (dormant source / upstream down) or
  openregister is absent. The legacy path is byte-for-byte unchanged, so
  configured envs keep working until an operator enables the OR source.

`getStatus` / `getPages` / `getPageContent` are **kept unchanged**: OR's
`searchPages` covers only free-text search; there are no lossless OR equivalents
for status-probe, space page-listing, or rendered single-page HTML, so retiring
those would lose behavior.

**Config NOT removed this change.** `xwiki_direct_url` (`SettingsService.php`),
the §2 admin settings field, `getBaseUrl()`, `DEFAULT_DIRECT_URL` and the
`SettingsManager` lookup all STAY — they are the documented fallback. They can be
deleted only AFTER the one operator step (below) makes OR-first the sole path.

**Operator step that unblocks full cut-over (next change, not this one):**
configure + enable the OpenConnector `xwiki` source (real URL + credentials,
`isEnabled=true`). Once OR's search returns 200 in all target envs, a follow-up
change deletes the §2 field + `getBaseUrl`/`DEFAULT_DIRECT_URL`/SettingsManager
fallback and routes `getStatus`/`getPages`/`getPageContent` through OR (requires
OR to grow status/list/page-content routes first).

## Capabilities

### Modified Capabilities

- `xwiki-proxy` — search now prefers OR's OpenConnector-routed endpoint and
  falls back to the legacy direct path when the OR source is dormant/down.

## Impact

- **PHP**: `lib/Service/XWikiService.php` — adds `IURLGenerator` dependency,
  `searchViaOpenRegister()`, `finishSearch()`; `search()` gains the OR-first
  preamble. No controller/route/frontend change — the consumer
  (`/api/xwiki/search`) keeps its shape, so the dashboard widget + sidebar tab
  are untouched.
- **Tests**: `tests/Unit/Service/XWikiServiceTest.php` — OR-200→mapped shape,
  OR space-filter passthrough, OR-503/non-200→legacy fallback. Suite 1557→1561.
- **No regression**: configured envs (NC xWiki app or non-empty
  `xwiki_direct_url`) keep working unchanged because the fallback IS the old
  code path; the OR call only wins on HTTP 200.
- **Backlog (separate, higher-risk):** the 7 other integration HTTP clients
  (Haal Centraal, KvK, OpenCorporates, Logius/Berichtenbox, WebhookProcessor,
  SMS, WhatsApp) are investigated in `design.md` — verdicts CONSUMABLE-NOW /
  NEEDS-GROUNDWORK / KEEP-APP-SPECIFIC; not migrated here.
