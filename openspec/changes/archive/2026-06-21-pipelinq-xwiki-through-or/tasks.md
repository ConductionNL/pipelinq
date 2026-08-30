# Tasks — pipelinq-xwiki-through-or

## 1. OR-first search re-point (safe-partial)

- [x] 1.1 Inject `IURLGenerator` into `XWikiService` constructor.
- [x] 1.2 Add `searchViaOpenRegister($query,$limit,$offset)` — internal
  `GET /apps/openregister/api/integrations/xwiki/search` via `IClientService`
  with `allow_local_address` + `OCS-APIREQUEST`; map OR rows to the widget shape
  on HTTP 200; return `null` on 503 / non-200 / malformed / OR-absent.
- [x] 1.3 Extract `finishSearch()` (shared space/tags filter + slice + cache) and
  call it from both the OR path and the legacy direct path.
- [x] 1.4 Make `search()` OR-first: try `searchViaOpenRegister()`, fall through to
  the unchanged `getBaseUrl()`/`fetchXml()` legacy path on `null`.

## 2. Preserve config + non-search methods

- [x] 2.1 Keep `xwiki_direct_url`, the §2 admin field, `getBaseUrl()`,
  `DEFAULT_DIRECT_URL` and the `SettingsManager` lookup as the documented
  fallback (deletion gated on the operator step — see design.md).
- [x] 2.2 Leave `getStatus` / `getPages` / `getPageContent` on the legacy path
  (no lossless OR equivalents).

## 3. Tests

- [x] 3.1 OR 200 → mapped widget shape (OR-first wins).
- [x] 3.2 OR rows pass through the shared space filter.
- [x] 3.3 OR 503 / non-200 → legacy fallback (configured env unaffected;
  empty-direct-URL → empty envelope, no fatal).

## 4. Verify

- [x] 4.1 `composer lint` + `phpcs --warning-severity=0` clean on `lib/`.
- [x] 4.2 Unit suite ≥ baseline (1557 → 1561 passing).
- [x] 4.3 Live `:8080` (source dormant): pipelinq `/api/xwiki/search` returns
  `200 {results:[],…}`; NC log shows OR-503 → legacy `fetchXml` fallback, no fatal.

## 5. SECONDARY (report only — not implemented here)

- [x] 5.1 Investigate the 7 integration clients; record per-client verdict
  (CONSUMABLE-NOW / NEEDS-GROUNDWORK / KEEP-APP-SPECIFIC) in design.md.
