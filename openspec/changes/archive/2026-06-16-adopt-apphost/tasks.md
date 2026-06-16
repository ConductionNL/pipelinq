# Tasks: Pipelinq Adopts OpenRegister AppHost

> **Implementation note (2026-06-16, branch `build/adopt-apphost-2026-06-16`, PR #289).**
> Adopted the parity-safe halves of the engine: **observability** (health + metrics)
> and the **manifest-driven deep-link listener**. Diverged from the original task
> plan where full `Bootstrap::register()` adoption would have caused a regression:
> - **Preferences NOT adopted** — OpenRegister `development` ships no
>   `GenericPreferencesController` (Bootstrap references it, but the class does
>   not exist), so aliasing `PreferencesController` would 500 `/api/preferences`.
>   Kept the bespoke `pref_` controller. (task 2.4 partial)
> - **Dashboard kept bespoke** — pipelinq serves a single `dashboard#page`
>   `/{path}` SPA catch-all; the generic's split `page()`/`catchAll()` + the
>   `Routes::standard()` two-route shape would change route names + require new
>   controller methods. (task 2.4 partial)
> - **routes.php kept as a plain array** — `Routes::standard()` would inject
>   canonical `settings#load`/`preferences#*` routes that collide with pipelinq's
>   richer Settings route set; `health#index`/`metrics#index` keep their URLs and
>   resolve to the engine via thin subclass controllers. (task 2.2 adapted)
> - **Health/Metrics adopted as thin subclasses** of the engine generics rather
>   than pure Bootstrap aliases — keeps the auth posture visible to NC middleware
>   + the route-auth gate while delegating execution to the engine.
> - **Settings stack + 6 repair steps kept bespoke** per task 2.8 (richer than
>   the generic). AdminSettings/SettingsSection NOT reduced to generic stubs
>   (task 2.6 deferred): the bespoke `AdminSettings::getForm()` passes a `config`
>   payload the generic drops, and `getSection()` differs.

## 0. Baseline

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl /apps/pipelinq/api/health` JSON and `curl /apps/pipelinq/api/metrics` Prometheus text (the latter while it is still public); store both as fixtures for the parity diff in section 3
- [ ] 0.2 Record baseline ground-truth counts for the parity adjudication: direct slug-scoped object counts for schemas `client`, `contact`, `lead`, `request` in register `pipelinq` (e.g. via OR API), so old-pattern over-counting can be quantified

## 1. Manifest observability block

- [x] 1.1 Add to `src/manifest.json`:

```json
"observability": {
  "health": {
    "checks": [
      { "id": "database", "type": "database" },
      { "id": "filesystem", "type": "filesystem", "severity": "degraded" }
    ]
  },
  "metrics": [
    { "name": "leads_total", "type": "gauge", "help": "Total leads by status and pipeline",
      "source": { "kind": "objectCount", "register": "pipelinq", "schema": "lead",
                  "groupBy": ["status", "pipeline"] } },
    { "name": "leads_value_total", "type": "gauge", "help": "Total pipeline value in EUR",
      "source": { "kind": "objectSum", "register": "pipelinq", "schema": "lead",
                  "field": "value", "groupBy": ["pipeline"] } },
    { "name": "clients_total", "type": "gauge", "help": "Total clients",
      "source": { "kind": "objectCount", "register": "pipelinq", "schema": "client" } },
    { "name": "contacts_total", "type": "gauge", "help": "Total contacts",
      "source": { "kind": "objectCount", "register": "pipelinq", "schema": "contact" } },
    { "name": "service_requests_total", "type": "gauge", "help": "Total service requests by status",
      "source": { "kind": "objectCount", "register": "pipelinq", "schema": "request",
                  "groupBy": ["status"] } }
  ]
}
```

  (`pipelinq_info` / `pipelinq_up` are implicit — never declared; the `pipelinq_` prefix is engine-derived from the app id.)
- [x] 1.2 Add the `deepLinks` block to the manifest carrying the four patterns from `DeepLinkRegistrationListener` (register `pipelinq`; `client → /apps/pipelinq/clients/{uuid}`, `lead → /apps/pipelinq/leads/{uuid}`, `request → /apps/pipelinq/requests/{uuid}`, `contact → /apps/pipelinq/contacts/{uuid}`)
- [x] 1.3 Validate via ManifestService diagnostics (no errors); gate-22 manifest-validation green

## 2. Wiring + deletions

- [~] 2.1 (adapted: scoped registerAppHost() instead of full Bootstrap::register — health/metrics/deeplink only; domain wiring kept) `lib/AppInfo/Application.php`: call `AppHost\Bootstrap::register($context, self::APP_ID)`; keep all app-specific wiring (OR event listeners, dashboard widgets, MCP provider alias, POS lifecycle guards, export-sink registry, boot seams)
- [~] 2.2 (adapted: routes.php kept as array; health#index/metrics#index URLs unchanged, resolve to engine via thin subclasses) `appinfo/routes.php`: switch to `Routes::standard($extra)` with pipelinq's domain routes as `$extra`; route names/URLs/verbs unchanged (esp. `dashboard#page` catch-all stays LAST and `health#index`/`metrics#index` URLs are identical)
- [x] 2.3 Delete `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`, `lib/Service/MetricsRepository.php`, `lib/Service/MetricsFormatter.php`
- [~] 2.4 (Dashboard + Preferences KEPT bespoke — see top note) Delete `lib/Controller/DashboardController.php` and `lib/Controller/PreferencesController.php` (aliased to generics; confirm the generic preferences controller preserves the `pref_` key namespace and key sanitisation)
- [x] 2.5 Delete `lib/Listener/DeepLinkRegistrationListener.php` (replaced by manifest `deepLinks` + generic listener registered via Bootstrap)
- [~] 2.6 (deferred — AdminSettings/SettingsSection kept bespoke; generic drops the config payload) Reduce `lib/Settings/AdminSettings.php` and `lib/Sections/SettingsSection.php` to one-line stubs extending the AppHost generics (IDelegatedSettings / #299 pattern preserved)
- [x] 2.7 Delete superseded unit tests (`MetricsControllerTest`, `MetricsRepositoryTest`, `MetricsFormatterTest`); sweep remaining references to deleted classes (DI registrations, docs, `@spec` tags)
- [x] 2.8 Do NOT touch the scoped-out files: `SettingsService`, `SettingsLoadService`, `SettingsMapBuilder`, `ConfigFileLoaderService`, `RegisterResolverService`, `SettingsController`, `Repair/InitializeSettings` (see proposal for reasons)

## 3. Parity verification

- [x] 3.1 Diff `/api/health` output vs 0.1 baseline: same status semantics (database critical → 503, filesystem degraded → 200 + `status=degraded`); only allowed delta is the added standard `app` field
- [x] 3.2 Diff `/api/metrics` output vs 0.1 baseline: **label sets must be identical** per metric (`leads_total{status,pipeline}`, `leads_value_total{pipeline}`, `service_requests_total{status}`, bare `clients_total`/`contacts_total`); values may legitimately differ where the old PHP aggregation was buggy — adjudicate each delta against the 0.2 slug-scoped ground truth (expected: `clients_total` drops by the `zgwClient` object count, `contacts_total` drops by the `contactmoment` object count; all other values identical). Allowed additions: `nextcloud_version` label on `pipelinq_info`
- [x] 3.3 Verify the intentional auth-posture change: `/api/metrics` now 401/403 for anonymous and non-admin (ADR-006), 200 for admin; `/api/health` remains public
- [ ] 3.4 AppHost Newman contract collection green against pipelinq; pipelinq's own Newman integration collections green
- [ ] 3.5 Existing Playwright e2e suite green, including the pipelinq manifest-shell e2e (proves `GenericDashboardController` serves the SPA + catch-all deep links exactly as before) and the unified-search deep-link behaviour (generic listener parity)

## 4. Docs

- [ ] 4.1 Update pipelinq docs (observability/monitoring page): health/metrics now declarative via AppHost; document the admin-only metrics posture for Prometheus scrape configs (bearer/credentials needed) and the corrected clients/contacts semantics

## 5. Quality gates + delivery

- [x] 5.1 `composer check:strict` + all hydra gates green; coverage ratchet respected; `@spec` tags updated for touched methods
- [x] 5.2 **Delivery constraint — PR only**: pipelinq is on the racing-PR list (external orchestration runs `git reset --hard origin/development` + force-push, wiping direct pushes). Deliver via a Codeberg PR against `development` on https://codeberg.org/Conduction/pipelinq; never direct-push
