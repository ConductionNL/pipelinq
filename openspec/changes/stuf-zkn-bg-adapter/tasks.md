# Tasks: stuf-zkn-bg-adapter

> **ADR corrections applied during implementation** (override the spec where it conflicts):
> - **ADR-037**: schemas + seeds are NOT written into the monolith
>   `lib/Settings/pipelinq_register.json`. They live in the fragment
>   `lib/Settings/register.d/40-stuf-zkn-bg-adapter.json`, deep-merged by
>   `ConfigFileLoaderService`. The loader was extended with the fleet-standard
>   **additive-union** rule for register `schemas[]` membership and
>   `components.objects[]` seeds (it previously replaced those lists wholesale),
>   covered by new unit tests in `RegisterFragmentMergeTest`. Schema slugs were
>   registered in `SettingsLoadService::SCHEMA_SLUGS` so the `{slug}_schema`
>   app-config keys resolve.
> - **ADR-022 / contact = NC entity**: StUF betrokkenen reuse the EXISTING
>   `contact` schema (no new person schema). Only the real OR ObjectService API
>   is used (`find`/`findAll`/`saveObject`).
> - **ADR-005**: inbound `/api/stuf/inkomend` is `#[PublicPage]` but verifies a
>   shared secret (fail-closed when unset) before any parsing; XML parsing is
>   XXE-safe (DOCTYPE rejected, no external entities, `LIBXML_NONET`); WSSE +
>   mutual-TLS secrets resolved from the vault at send time, server-cert
>   verification never disabled.
> - **External StUF endpoint** is abstracted behind `StufTransportInterface`
>   (DI-bound to `StufHttpClient`); all tests inject an in-memory fake — no live
>   endpoint is required to build or test.
> - Services live under `lib/Service/Stuf/`; exceptions under `lib/Exception/`.

## 1. Data Model & Schema Registration

- [x] 1.1 Add `StufEndpoint` schema (in `register.d` fragment per ADR-037, not the monolith)
- [x] 1.2 Add `StufMessage` schema (fragment)
- [x] 1.3 Add `ZaaksysteemMapping` schema (fragment)
- [x] 1.4 Register all three schemas in the register `schemas` membership (additive-union fragment + loader rule + slug registration)
- [x] 1.5 Add seed data for StufEndpoint examples (Amersfoort Key2Zaken + Rotterdam PinkRoccade, vault auth refs)
- [x] 1.6 Add seed data for StufMessage examples (Lk01 outbound, Bv01-correlated, Lk02 inbound)
- [x] 1.7 Add seed data for ZaaksysteemMapping examples (request→zaak, contact→NPS)

## 2. Backend Core Services

- [x] 2.1 Create `lib/Service/Stuf/StufAdapterService.php` (creeerZaak, actualiseerZaak, geefZaakDetails, vrijBericht, genereerZaakIdentificatie) — full orchestration, not stubs
- [x] 2.2 Create `lib/Service/Stuf/StufEnvelopeBuilder.php` (Lk01/Lk02/Lv01/Du01 + vrijBericht; buildStuurgegevens, ULID generateReferentienummer, currentTimestampStuf yyyyMMddHHmmssSSS Europe/Amsterdam)
- [x] 2.3 Create `lib/Service/Stuf/StufHttpClient.php` (implements StufTransportInterface; WSSE + TLS from vault via StufCredentialResolver; server-cert verify never disabled; timing returned)
- [x] 2.4 Create `lib/Service/Stuf/StufMessageHandler.php` (logOutbound, logInboundMessage, correlateInbound by referentienummer, recordRetry, transitionStatus)
- [x] 2.5 Create `lib/Service/Stuf/StufMessageParser.php` (parseBevestiging, parseZaakDetails, parseError, parseInbound; XXE-safe `loadXxeSafe` helper replaces the spec's `extractNamespaceValue`)
- [x] 2.6 Create `lib/Service/Stuf/CircuitBreakerService.php` (checkEndpoint, recordFailure, resetEndpoint, isCircuitOpen; IAppConfig-backed state, 4-failure threshold, 5-min cooldown)
- [x] 2.7 Create `lib/Service/Stuf/ContactBetrokkeneMapper.php` (linkContact, findOrCreateBetrokkene with geefBetrokkene query-before-create, getContactMapping, bsnFromContact)

## 3. API Endpoints

- [x] 3.1 Create `lib/Controller/StufController.php` (outbound, inkomend, endpoints, messages)
- [x] 3.2 Register routes in `appinfo/routes.php` (static paths before the SPA wildcard; inkomend `#[PublicPage]`)
- [x] 3.3 Authorization checks (admin via `#[AuthorizedAdminSetting]` except inkomend; inkomend verifies a shared-secret header, fail-closed)

## 4. Frontend — Admin Configuration & Audit

- [x] 4.1 StUF Endpoints admin page — declarative manifest `index`+`detail` pages (ADR-036/037 fragment `src/manifest.d/40-stuf-zkn-bg-adapter.json`), schema-driven CRUD via the generic renderer
- [x] 4.2 StUF Audit Log admin page — declarative manifest `index`+`detail` pages over the `stufMessage` schema (columns: date, direction, berichtSoort, functie, status, httpStatus, duration; full envelope + retries visible in the detail sidebar)
- [x] 4.3 Navigation — footer-section menu entries "StUF Endpoints" + "StUF Audit Log"
- [~] 4.1/4.2 bespoke extras (Test-Connection button, CSV/JSON export, inline XML pretty-print) DEFERRED — would require a custom Vue page + running-instance verification; the generic renderer covers list/detail/CRUD. Follow-up issue to be filed.

## 5. Integration with Request & Contact Flows

- [x] 5.1 Request → zaak registration: `StufAdapterService::creeerZaak()` is the public wrapper (creates ZaaksysteemMapping on success, logs StufMessage + raises needs-input on error)
- [x] 5.2 Contact → betrokkene sync: `ContactBetrokkeneMapper::findOrCreateBetrokkene()` + `linkContact()`, wired into creeerZaak via `resolveBetrokkenen()`
- [~] 5.3 Detail-view UI integrations (zaak-id badge + "Register to Zaaksysteem" button on Request/Contact detail) DEFERRED — needs a running instance for UI verification; backend wrappers exist. Follow-up issue to be filed.

## 6. Retry, Idempotency & Resilience

- [x] 6.1 Retry with exponential backoff (5s/30s/2m/10m) reusing the same referentienummer; each attempt recorded via recordRetry (sleep gated by app-config so tests run instantly)
- [x] 6.2 Circuit breaker: checkEndpoint before send, opens on 4th failure for 5 min, raises needs-input
- [x] 6.3 Sync-query timeout handling: geefZaakDetails 30s, no auto-retry on timeout, raises StufTimeoutException + needs-input

## 7. Validation & Error Handling

- [x] 7.1 zaaktypeMappings on the endpoint; builder validates request.type pre-build, raises ZaaktypeNotMappedException
- [x] 7.2 Document payload ceiling (25 MiB pre-base64), raises PayloadTooLargeException before transmission
- [x] 7.3 Fo02 fault parsing; transient (StUF051/052, 5xx, network) vs permanent classification for breaker vs needs-input

## 8. Logging & Observability

- [x] 8.1 Debug-level logging across builder/http-client/parser/circuit-breaker
- [x] 8.2 Graceful degradation: vault/TLS load failures logged at ERROR; envelope never transmitted without resolvable credentials/cert
- [~] 8.3 Per-endpoint health badge / last-5 success-rate DEFERRED to the bespoke-UI follow-up (the audit-log list + status field already expose per-message state)

## 9. Testing & QA

- [x] 9.1 Unit tests for StufEnvelopeBuilder (valid XML, unique ULID, timestamp format, base64 no-wrap, WSSE injection, zaaktype/payload exceptions, well-formedness)
- [x] 9.2 Unit tests for StufMessageParser (Bv01/La01/Fo02 parse + XXE-rejection + billion-laughs rejection)
- [x] 9.3 Unit tests for CircuitBreakerService (increment, trip at 4, cooldown auto-reset, reset, endpoint isolation)
- [x] 9.4 Integration tests (fake transport): creeerZaak flow, 503→retry-same-referentienummer→success, coexistence no-op, unknown vrijBericht; plus contact-mapper dedup + credential-resolver + controller inbound-secret tests
- [~] 9.5 Manual QA against the VNG StUF testbed DEFERRED — requires a live external zaaksysteem endpoint.

## 10. Documentation & Knowledge Transfer

- [~] 10.1 README.md app-docs page DEFERRED — documentation files are not created proactively; to be authored via the docs workflow with VNG StUF links.
- [x] 10.2 Inline code comments (stuurgegevens header, WSSE/TLS loading, breaker threshold/cooldown, dedup query-before-create)

## 11. Verification & Deployment

- [~] 11.1 `npm run build` DEFERRED — node_modules not provisioned in the build sandbox; the manifest fragment + l10n JS/JSON validated (`node --check`, JSON parse, schema page/menu-shape conformance against the @conduction/nextcloud-vue app-manifest schema).
- [~] 11.2 Verify schemas registered in a live OpenRegister instance DEFERRED — needs a running instance.
- [x] 11.3 Run test suite — `composer check:strict` green: lint + phpcs + phpmd + psalm + phpstan + 492 phpunit tests pass.
- [~] 11.4 Verify endpoint UI renders DEFERRED — needs a running instance.
- [~] 11.5 Verify audit-log UI renders DEFERRED — needs a running instance.
- [~] 11.6 Verify API routes respond DEFERRED — needs a running instance (routes registered + auth attributes asserted by controller unit test).
- [~] 11.7 Manual smoke test DEFERRED — needs a running instance.
- [x] 11.8 Bump `appinfo/info.xml` version (0.2.28 → 0.2.29). CHANGELOG update left to the release process.

## 12. Seed Data Generation Task

- [x] 12.1 Seed data ships in the register.d fragment and is unioned into `components.objects[]` (verified by the loader union unit tests).
- [~] 12.2 Confirm StufEndpoint examples visible in admin UI after fresh install DEFERRED — needs a running instance.
- [~] 12.3 Confirm StufMessage/ZaaksysteemMapping examples load DEFERRED — needs a running instance.
- [x] 12.4 Seed gemeente codes/system names use realistic Dutch values (Amersfoort 0307 Key2Zaken, Rotterdam 0599 PinkRoccade).
