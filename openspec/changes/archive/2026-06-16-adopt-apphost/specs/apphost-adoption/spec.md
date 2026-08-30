---
status: proposed
---

# Pipelinq AppHost Adoption

## Purpose

Pipelinq's health/metrics endpoints and boilerplate plumbing (dashboard SPA serving, per-user preferences, admin-settings registration, deep-link registration) run on the OpenRegister AppHost, replacing hand-written copies — most importantly replacing the PHP-side OR-object aggregation in `MetricsRepository` with the engine's portable `objectCount`/`objectSum` descriptors.

**Cross-references**: `openregister/openspec/changes/apphost-observability-engine/`, `openregister/openspec/changes/apphost-boilerplate-controllers/`

---

## ADDED Requirements

### Requirement: Declarative Observability with Engine-Side Aggregation

Pipelinq SHALL serve `/apps/pipelinq/api/health` and `/apps/pipelinq/api/metrics` through the AppHost engine from descriptors in `src/manifest.json`. Lead, client, contact, and request metrics MUST be computed by OpenRegister's portable aggregation layer using register/schema slugs (`pipelinq`/`lead`, `client`, `contact`, `request`) — never by fetching object JSON and aggregating in PHP, and never by schema-title LIKE patterns.

#### Scenario: Lead metrics aggregated by the engine with identical label sets

- **GIVEN** a seeded instance with leads carrying `status`, `pipeline`, and numeric `value` fields
- **WHEN** an admin requests `GET /apps/pipelinq/api/metrics`
- **THEN** the output MUST contain `pipelinq_leads_total{status,pipeline}` and `pipelinq_leads_value_total{pipeline}` with values matching slug-scoped engine aggregation, plus `pipelinq_service_requests_total{status}`, `pipelinq_clients_total`, `pipelinq_contacts_total`, and the implicit `pipelinq_info` / `pipelinq_up`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Slug-scoped counts replace buggy title patterns

- **GIVEN** an instance containing objects of schemas `client`, `zgwClient`, `contact`, and `contactmoment`
- **WHEN** an admin requests `GET /apps/pipelinq/api/metrics`
- **THEN** `pipelinq_clients_total` MUST count only `client`-schema objects (excluding `zgwClient`) and `pipelinq_contacts_total` MUST count only `contact`-schema objects (excluding `contactmoment`)
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: Health parity

- **GIVEN** a healthy instance
- **WHEN** `GET /apps/pipelinq/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `checks.database = "ok"` and `checks.filesystem = "ok"`; a database failure MUST drive `status=error` + HTTP 503 and a filesystem failure MUST drive `status=degraded` + HTTP 200
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: ADR-006 Auth Posture Enforced by the Engine

The metrics endpoint SHALL be admin-only and the health endpoint SHALL be public, both enforced by the AppHost generic controllers. This intentionally changes today's `#[PublicPage]` metrics endpoint, which violates ADR-006.

#### Scenario: Metrics endpoint rejects non-admin access

- **GIVEN** the AppHost-served metrics endpoint
- **WHEN** `GET /apps/pipelinq/api/metrics` is called anonymously or as a non-admin user
- **THEN** the request MUST be rejected (401/403), while an admin request returns HTTP 200 with `Content-Type: text/plain; version=0.0.4`
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: Boilerplate Served by AppHost Generics with Behavioural Parity

Pipelinq SHALL serve its SPA page (catch-all `dashboard#page` route), per-user preferences (`pref_`-namespaced, key-sanitised), admin-settings registration (IDelegatedSettings), and OR deep-link registration (four manifest-declared patterns for `client`/`lead`/`request`/`contact`) through the AppHost generic classes, deleting the local copies. Route names, URLs, verbs, and response shapes MUST be unchanged.

#### Scenario: SPA dashboard and deep links render after adoption

- **GIVEN** the local `DashboardController` is deleted and the route is aliased to the AppHost generic
- **WHEN** a user opens `/apps/pipelinq/` and navigates to a deep link such as `/apps/pipelinq/leads/{uuid}`
- **THEN** the manifest-shell SPA MUST render with navigation, pages, and the lead detail surface exactly as before (verified by the existing pipelinq manifest-shell Playwright e2e suite)

#### Scenario: Existing user preferences keep resolving

- **GIVEN** a user with a preference previously written by the old controller (e.g. the CnSupportDialog "seen" flag under `pref_*`)
- **WHEN** the frontend reads `GET /apps/pipelinq/api/preferences/{key}` after adoption
- **THEN** the stored value MUST be returned unchanged, and writes MUST persist to the same `pref_`-namespaced IConfig user keys
- @e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

### Requirement: App-Specific Settings Stack Remains Out of Scope

The pipelinq settings stack (`SettingsService`, `SettingsLoadService`, `SettingsMapBuilder`, `ConfigFileLoaderService`, `RegisterResolverService`, `SettingsController`, `Repair/InitializeSettings`) SHALL be retained unchanged: it carries app-specific logic (three-register import, `register.d/` fragment deep-merge, 45+ tenant tunables, default pipeline/queue/skill seeding, API-token/OAuth/MCP admin endpoints) that the AppHost `AppHostSettingsService` generalisation does not cover.

#### Scenario: Settings behaviour unchanged after adoption

- **GIVEN** the AppHost adoption is deployed
- **WHEN** an admin reads and re-imports settings via `GET /apps/pipelinq/api/settings` and `POST /apps/pipelinq/api/settings/reimport`
- **THEN** the responses MUST be byte-identical in shape to pre-adoption, with all three register ids (`register`, `portal_register`, `sla_register`) and schema keys resolved as before
- @e2e exclude API-only endpoint — covered by pipelinq's existing Newman integration collections
