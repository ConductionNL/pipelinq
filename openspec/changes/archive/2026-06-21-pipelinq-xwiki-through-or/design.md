# Design — pipelinq-xwiki-through-or

## The behavior-preservation decision: full re-point vs safe-partial

**Decision: SAFE-PARTIAL re-point.**

### Why not a full re-point now

The OR groundwork (openregister `XwikiLinkService::searchPages` →
`XwikiProvider` → OpenConnector `xwiki` source) is real, but the OpenConnector
`xwiki` source is **dormant**: `isEnabled=false` with a placeholder URL. Live
probe on `:8080` confirms it:

```
GET /apps/openregister/api/integrations/xwiki/search?q=test&limit=5
→ HTTP 503 {"error":"xWiki source is not available","details":{"cause":"upstream-service-down"}}
```

Pipelinq's xWiki widget, by contrast, WORKS today in a configured env: it
resolves the base URL from the `OCA\Xwiki` NC app's `SettingsManager` (or the
admin `xwiki_direct_url`) and queries xWiki's REST API directly. A naive cut-over
to OR's endpoint would make every configured env return empty/error until an
operator separately configures + enables the OR source — a regression.

### The safe-partial design (no regression)

`XWikiService::search()` becomes OR-first with legacy fallback:

1. Cache check (unchanged).
2. **OR-first** — `searchViaOpenRegister($query,$limit,$offset)`:
   internal server-side `GET /apps/openregister/api/integrations/xwiki/search`
   via `IURLGenerator::getAbsoluteURL` + `IClientService` with
   `nextcloud.allow_local_address=true` and `OCS-APIREQUEST: true` (the exact
   pattern `NoteEventService::fetchEntityData` already uses on `:8080`).
   - HTTP 200 → map OR rows `{id|reference,title,space,url,modified,tags}` to the
     widget shape and return via the shared `finishSearch()` (space/tags filter +
     slice + cache). **OR wins.**
   - 503 (dormant/down — the client throws), non-200, malformed body, or OR
     absent → return `null`. **Fall through.**
3. **Legacy fallback** (`null` from step 2) — the original `getBaseUrl()` →
   `fetchXml()` → `parseSearchResults()` path, byte-for-byte unchanged, then the
   same `finishSearch()`.

Both paths share `finishSearch()` so the result envelope + space/tags semantics
are identical regardless of source. The consumer endpoint `/api/xwiki/search`
keeps its shape, so `XWikiController`, the Pinia store, the dashboard widget and
the sidebar tab are untouched.

**No-regression proof (live, `:8080`, source dormant):** a fresh
`GET /apps/pipelinq/api/xwiki/search?q=…` returns `200 {results:[],total:0,…}`.
The NC log shows OR returning 503 (client throws) immediately followed by
`"Host xwiki was not connected to because it violates local access rules"` — the
legacy `fetchXml` attempt to the placeholder `http://xwiki:8080`. So the fallback
fires exactly as before; an env with a reachable xWiki would have `fetchXml`
succeed in that same step, identical to pre-change behavior.

### What `getStatus` / `getPages` / `getPageContent` do

Kept unchanged. OR's `searchPages` is free-text-search only; there is no lossless
OR route for the availability probe, the per-space page list, or the
sanitized rendered single-page HTML. Repointing them would lose behavior, so they
remain on the legacy path and are noted for a future change once OR grows
matching routes.

## The operator step (unblocks deleting §2 config)

`xwiki_direct_url` (`lib/Service/SettingsService.php:227`), the §2 admin field
(`src/views/settings/Settings.vue`), `getBaseUrl()`, `DEFAULT_DIRECT_URL`, and
the `SettingsManager` lookup STAY in this change — they are the documented
fallback. They become deletable only after:

> **Operator step:** in OpenConnector, set the `xwiki` Source's real `location`
> (xWiki base URL) + credentials and `isEnabled=true`. Verify
> `GET /apps/openregister/api/integrations/xwiki/search` returns 200 in every
> target env.

A follow-up change then removes the §2 field + the direct-URL fallback and (after
OR grows status/list/page routes) repoints `getStatus`/`getPages`/
`getPageContent`.

## SECONDARY — investigation of the 7 integration HTTP clients (report only, not migrated)

Each pipelinq client checked against an existing OR provider leaf
(`openregister/lib/Service/Integration/Providers/`) and an existing OpenConnector
source. The xWiki seam is the model for "the same kind of groundwork":
OpenConnector source (slug + creds, dormant) + OR `XwikiProvider`
(`AbstractIntegrationProvider`, routes via `ExternalIntegrationRouter`) + OR
`XwikiLinkService` + OR routes.

| Client | External service | OR leaf exists? | OC source exists? | Verdict | Reason |
|--------|------------------|-----------------|-------------------|---------|--------|
| `KvkApiClient` | KVK Handelsregister Zoeken (REST/JSON, apikey) | No | No | **NEEDS-GROUNDWORK** | Generic REST/JSON GET — fits the generic OR leaf, but no `kvk` source/provider exists yet; build them first (low-risk, xWiki-shaped). |
| `OpenCorporatesApiClient` | OpenCorporates company search (REST/JSON) | No | No | **NEEDS-GROUNDWORK** | Generic REST/JSON GET, OR-ownable, but no `opencorporates` source/provider yet; build them first (low-risk). |
| `HaalCentraalClient` | RvIG BRP Personen v2 (OAuth2 + mTLS, HAL+JSON) | No | No | **NEEDS-GROUNDWORK** | Gov BRP: mTLS client cert + OAuth2 client_credentials + HAL+JSON normalize; needs a dedicated `brp` OpenConnector source/adapter + `BrpProvider` — not a generic leaf. |
| `LogiusConnector` | Logius Berichtenbox BBK 1.7 (OAuth2 + RSA-SHA256 sign + HMAC webhook + mTLS) | No | Yes (dormant: `BerichtenboxSourceAdapter`, feature-flagged) | **NEEDS-GROUNDWORK** | OC `logius-berichtenbox` source exists but dormant; needs a `BerichtenboxProvider` in OR + active transport + inbound webhook route. |
| `SmsAdapter` | CmCom / MessageBird / Twilio (via `SmsProviderFactory`) | No | No | **NEEDS-GROUNDWORK** | Multi-provider send + inbound STOP/opt-in webhook + consent/budget gates; needs per-provider OC sources + a unified `SmsProvider`. |
| `WhatsAppAdapter` | Meta WhatsApp Cloud API + BSPs | No | No | **NEEDS-GROUNDWORK** | Template-approval + 24h session window + dual provider + cost capture; needs Cloud/BSP OC sources + `WhatsAppProvider`. |
| `WebhookProcessorService` | SendGrid / SES / Twilio delivery events | n/a (not a client) | n/a | **KEEP-APP-SPECIFIC** | Inbound event NORMALIZER, not an outbound HTTP client; translates provider event shapes into pipelinq `BlastDelivery` state + consent/attribution side-effects. Outbound dispatch already goes via OpenConnector (ADR-005). The receiver/state machine is pipelinq-owned; OR's generic leaf can't own it. |

**Net:** no client is CONSUMABLE-NOW (none has an existing usable OR
leaf + source). KvK + OpenCorporates are the cheapest next builds (generic
REST, xWiki-shaped). HaalCentraal / Logius / SMS / WhatsApp are gov-protocol /
PKI / multi-provider-messaging — each needs a bespoke source + provider. The
WebhookProcessor stays app-specific. This table is the backlog; nothing is
migrated in this change.

### Exact groundwork per NEEDS-GROUNDWORK client (modeled on xWiki)

For the generic ones (KvK, OpenCorporates):
1. OpenConnector `source` seed row (slug `kvk` / `opencorporates`, REST type,
   tunable `location`, apikey/none auth, `isEnabled=false`).
2. OR `KvkProvider` / `OpenCorporatesProvider` extending
   `AbstractIntegrationProvider`, `SOURCE_ID` matching the slug, routing via
   `ExternalIntegrationRouter`, implementing `search()`.
3. OR route `GET /api/integrations/{kvk|opencorporates}/search`.
4. Pipelinq safe-partial re-point identical to this change (OR-first +
   legacy fallback).

For the gov/multi-provider ones (HaalCentraal, Logius, SMS, WhatsApp): a
dedicated OpenConnector `SourceAdapter` carrying the protocol/crypto (mTLS,
OAuth2, RSA/HMAC sign, template/session rules) + an OR provider exposing the
normalized surface; higher-risk, separately gated.
