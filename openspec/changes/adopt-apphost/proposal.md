---
kind: code
---

# Proposal: Pipelinq Adopts OpenRegister AppHost (Observability + Boilerplate)

## Problem

Pipelinq is the poster child for why the AppHost observability engine exists. Its `MetricsController` cannot aggregate OpenRegister object JSON in the database: portable JSON-SQL across MySQL and PostgreSQL was too hard to maintain in a leaf app, so `MetricsRepository` **fetches every matching OR object row and aggregates the decoded JSON in PHP** (`getLeadCounts()`, `getLeadValueByPipeline()`, `getRequestCounts()` — all full-table fetch + `json_decode` loops, explicitly commented "Aggregate in PHP for DB-portability"). The OR engine's aggregation layer (`objectCount`/`objectSum` descriptors) solves exactly this problem, once, portably, in the layer that owns the data.

On top of the headline problem, the hand-written copies have drifted into real defects:

- **ADR-006 violation**: `MetricsController::index()` is `#[PublicPage]` — metrics must be admin-only. The engine owns the auth posture, so adoption fixes this.
- **Pattern-count bugs**: `clients_total` / `contacts_total` count objects whose *schema title* matches `LIKE '%client%'` / `'%contact%'`. The `%client%` pattern also matches the `zgwClient` schema ("ZGW Client Credentials") and `%contact%` also matches `contactmoment` ("Contactmoment") — both metrics over-count today. Slug-scoped `objectCount` descriptors fix this.
- ~600 lines of health/metrics plumbing (`HealthController`, `MetricsController`, `MetricsRepository`, `MetricsFormatter`) plus the usual Dashboard/Preferences/AdminSettings/SettingsSection/DeepLink boilerplate, all drifted copies of the template skeleton, each needing its own PR for every fleet-wide fix.

## Proposed Change

Adopt the OpenRegister AppHost per `apphost-observability-engine` and `apphost-boilerplate-controllers`: declare health checks and metrics in `src/manifest.json`, alias the boilerplate routes to the AppHost generics via `Bootstrap::register()`, and delete the local copies. Probe/scrape URLs (`/apps/pipelinq/api/health`, `/apps/pipelinq/api/metrics`) do not change.

### Observability descriptors (resolved slugs)

Health (exact parity with today): `database` (critical) + `filesystem` (severity `degraded`).

Metrics — register slug `pipelinq`, schema slugs resolved from `lib/Settings/pipelinq_register.json`:

| Metric today | Old implementation | Descriptor |
|---|---|---|
| `pipelinq_leads_total{status,pipeline}` | PHP-side fetch-all + json_decode group-by | `objectCount` register `pipelinq`, schema `lead`, groupBy `["status","pipeline"]` |
| `pipelinq_leads_value_total{pipeline}` | PHP-side fetch-all + float sum | `objectSum` register `pipelinq`, schema `lead`, field `value`, groupBy `["pipeline"]` |
| `pipelinq_clients_total` | schema-title `LIKE '%client%'` (over-counts `zgwClient`) | `objectCount` register `pipelinq`, schema `client` |
| `pipelinq_contacts_total` | schema-title `LIKE '%contact%'` (over-counts `contactmoment`) | `objectCount` register `pipelinq`, schema `contact` |
| `pipelinq_service_requests_total{status}` | PHP-side fetch-all + json_decode group-by | `objectCount` register `pipelinq`, schema `request`, groupBy `["status"]` |
| `pipelinq_info` / `pipelinq_up` | hand-formatted in `MetricsFormatter` | implicit — never declared |

### Deletions

- `lib/Controller/HealthController.php` (route aliased to `GenericHealthController`)
- `lib/Controller/MetricsController.php` (route aliased to `GenericMetricsController`; auth posture becomes admin-only per ADR-006 — intentional fix)
- `lib/Service/MetricsRepository.php` — the PHP aggregation dies
- `lib/Service/MetricsFormatter.php` — exposition format is engine-owned
- `lib/Controller/DashboardController.php` (alias to `GenericDashboardController`; single `dashboard#page` catch-all route preserved)
- `lib/Controller/PreferencesController.php` (alias to `GenericPreferencesController`; the `pref_` key namespace and key-sanitisation behaviour must be preserved per boilerplate parity rule 3)
- `lib/Listener/DeepLinkRegistrationListener.php` — its four patterns (`client`/`lead`/`request`/`contact` → `/apps/pipelinq/{plural}/{uuid}`) move to the manifest `deepLinks` block consumed by `GenericDeepLinkRegistrationListener`
- `lib/Settings/AdminSettings.php` + `lib/Sections/SettingsSection.php` bodies → one-line stubs extending the AppHost generics (NC requires concrete classes in the app namespace for `<settings>` entries)
- `tests/Unit/Controller/MetricsControllerTest.php`, `tests/Unit/Service/MetricsRepositoryTest.php`, `tests/Unit/Service/MetricsFormatterTest.php` — superseded by OR engine unit tests + the AppHost Newman contract collection
- `lib/AppInfo/Application.php`: boilerplate registrations replaced by `Bootstrap::register()`; **the substantial app-specific wiring stays** (OR event listeners, dashboard widgets, MCP provider alias, POS lifecycle guards, export-sink registry, boot-time appointment seams) — pipelinq's Application.php shrinks but does not reach the ~20-line shell floor
- `appinfo/routes.php`: boilerplate routes (dashboard catch-all, preferences, health, metrics) come from `Routes::standard()`; pipelinq's ~480 lines of domain routes are passed as `$extra`

### Scoped OUT (kept, with reasons)

The AppHost `AppHostSettingsService` generalises the petstore skeleton (register/schema config resolution + OR availability). Pipelinq's settings stack carries app-specific logic well beyond that, so it stays:

- **`lib/Service/SettingsService.php`** — ~100 `CONFIG_KEYS` spanning **three registers** (`register`, `portal_register`, `sla_register`), 45+ `TUNABLE_DEFAULTS` (SLA business hours, POS EOD, receipt company data, export retention, …), user-setting defaults, and default pipeline/queue/skill creation. Only the trivial key get/set overlaps with the generic.
- **`lib/Service/SettingsLoadService.php`** — multi-register import (slugs `pipelinq`, `pipelinq-portal`, `sla`), divergent SLA config-key naming (`sla_policy_schema` ≠ auto-derived `slaPolicy_schema`), default-view selection. The generic repair step imports one register JSON by appId; it does not cover this.
- **`lib/Service/SettingsMapBuilder.php`** — collaborator of SettingsLoadService (slug→id maps, default-view resolution); stays with it.
- **`lib/Service/ConfigFileLoaderService.php`** — the `register.d/` fragment deep-merge loader (additive-list union + version folding per ADR-037, 23 fragment files today). Not part of the AppHost contract; if a second app grows the same need, that is the signal to promote it into the engine, not to fork it here.
- **`lib/Service/RegisterResolverService.php`** — has its own spec (`pipelinq-or-register-resolver`) and four domain consumers (QueueService, DefaultQueueService, ContactVcard services). Not an AppHost concern.
- **`lib/Controller/SettingsController.php`** — not pure boilerplate: beyond index/create it serves admin objecten-access, API-token, OAuth, and MCP endpoints backed by `ApiAuthService`/`ObjectenAccessService`, plus user settings. `GenericSettingsController` covers only index/create/load; wholesale deletion would drop real endpoints. Kept as-is; a later shrink onto the generic base class is possible but out of scope.
- **`lib/Repair/InitializeSettings.php`** — beyond the generic register-JSON import it seeds default pipelines, queues, skills, and system tags (lead sources, request channels). Kept; extending `GenericInitializeSettings` with a post-import hook is a possible follow-up.

## Impact

- **Deleted**: ~900 lines of controllers/services/listener + 3 unit-test files; **modified**: `src/manifest.json`, `appinfo/routes.php`, `lib/AppInfo/Application.php`, AdminSettings/SettingsSection stubs.
- **Intentional behaviour deltas** (documented, verified in tasks): metrics endpoint becomes admin-only (ADR-006 fix); `clients_total`/`contacts_total` values may drop where the old LIKE patterns over-counted (`zgwClient`, `contactmoment`) — slug-scoped values are the correct ones; implicit `pipelinq_info` gains a `nextcloud_version` label; health response gains the standard `app` field.
- **Delivery constraint**: pipelinq is on the racing-PR list — an external orchestration force-resets `development`, wiping direct pushes. Delivery MUST be via a Codeberg PR, never direct push.

## Dependencies

Chained: `apphost-observability-engine` (descriptor execution, generic health/metrics controllers, Newman contract collection), `apphost-boilerplate-controllers` (`Bootstrap::register()`, `Routes::standard()`, generic Dashboard/Preferences/AdminSettings/SettingsSection/DeepLink classes).
