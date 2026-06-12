# Tasks: Pipelinq Adopts OpenRegister AppHost

## 0. Baseline

- [ ] 0.1 Capture baseline on a seeded dev instance: `curl /apps/pipelinq/api/health` JSON and `curl /apps/pipelinq/api/metrics` Prometheus text (the latter while it is still public); store both as fixtures for the parity diff in section 3
- [ ] 0.2 Record baseline ground-truth counts for the parity adjudication: direct slug-scoped object counts for schemas `client`, `contact`, `lead`, `request` in register `pipelinq` (e.g. via OR API), so old-pattern over-counting can be quantified

## 1. Manifest observability block

- [ ] 1.1 Add to `src/manifest.json`:

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
- [ ] 1.2 Add the `deepLinks` block to the manifest carrying the four patterns from `DeepLinkRegistrationListener` (register `pipelinq`; `client → /apps/pipelinq/clients/{uuid}`, `lead → /apps/pipelinq/leads/{uuid}`, `request → /apps/pipelinq/requests/{uuid}`, `contact → /apps/pipelinq/contacts/{uuid}`)
- [ ] 1.3 Validate via ManifestService diagnostics (no errors); gate-22 manifest-validation green

## 2. Wiring + deletions

- [ ] 2.1 `lib/AppInfo/Application.php`: call `AppHost\Bootstrap::register($context, self::APP_ID)`; keep all app-specific wiring (OR event listeners, dashboard widgets, MCP provider alias, POS lifecycle guards, export-sink registry, boot seams)
- [ ] 2.2 `appinfo/routes.php`: switch to `Routes::standard($extra)` with pipelinq's domain routes as `$extra`; route names/URLs/verbs unchanged (esp. `dashboard#page` catch-all stays LAST and `health#index`/`metrics#index` URLs are identical)
- [ ] 2.3 Delete `lib/Controller/HealthController.php`, `lib/Controller/MetricsController.php`, `lib/Service/MetricsRepository.php`, `lib/Service/MetricsFormatter.php`
- [ ] 2.4 Delete `lib/Controller/DashboardController.php` and `lib/Controller/PreferencesController.php` (aliased to generics; confirm the generic preferences controller preserves the `pref_` key namespace and key sanitisation)
- [ ] 2.5 Delete `lib/Listener/DeepLinkRegistrationListener.php` (replaced by manifest `deepLinks` + generic listener registered via Bootstrap)
- [ ] 2.6 Reduce `lib/Settings/AdminSettings.php` and `lib/Sections/SettingsSection.php` to one-line stubs extending the AppHost generics (IDelegatedSettings / #299 pattern preserved)
- [ ] 2.7 Delete superseded unit tests (`MetricsControllerTest`, `MetricsRepositoryTest`, `MetricsFormatterTest`); sweep remaining references to deleted classes (DI registrations, docs, `@spec` tags)
- [ ] 2.8 Do NOT touch the scoped-out files: `SettingsService`, `SettingsLoadService`, `SettingsMapBuilder`, `ConfigFileLoaderService`, `RegisterResolverService`, `SettingsController`, `Repair/InitializeSettings` (see proposal for reasons)

## 3. Parity verification

- [ ] 3.1 Diff `/api/health` output vs 0.1 baseline: same status semantics (database critical → 503, filesystem degraded → 200 + `status=degraded`); only allowed delta is the added standard `app` field
- [ ] 3.2 Diff `/api/metrics` output vs 0.1 baseline: **label sets must be identical** per metric (`leads_total{status,pipeline}`, `leads_value_total{pipeline}`, `service_requests_total{status}`, bare `clients_total`/`contacts_total`); values may legitimately differ where the old PHP aggregation was buggy — adjudicate each delta against the 0.2 slug-scoped ground truth (expected: `clients_total` drops by the `zgwClient` object count, `contacts_total` drops by the `contactmoment` object count; all other values identical). Allowed additions: `nextcloud_version` label on `pipelinq_info`
- [ ] 3.3 Verify the intentional auth-posture change: `/api/metrics` now 401/403 for anonymous and non-admin (ADR-006), 200 for admin; `/api/health` remains public
- [ ] 3.4 AppHost Newman contract collection green against pipelinq; pipelinq's own Newman integration collections green
- [ ] 3.5 Existing Playwright e2e suite green, including the pipelinq manifest-shell e2e (proves `GenericDashboardController` serves the SPA + catch-all deep links exactly as before) and the unified-search deep-link behaviour (generic listener parity)

## 4. Docs

- [ ] 4.1 Update pipelinq docs (observability/monitoring page): health/metrics now declarative via AppHost; document the admin-only metrics posture for Prometheus scrape configs (bearer/credentials needed) and the corrected clients/contacts semantics

## 5. Quality gates + delivery

- [ ] 5.1 `composer check:strict` + all hydra gates green; coverage ratchet respected; `@spec` tags updated for touched methods
- [ ] 5.2 **Delivery constraint — PR only**: pipelinq is on the racing-PR list (external orchestration runs `git reset --hard origin/development` + force-push, wiping direct pushes). Deliver via a Codeberg PR against `development` on https://codeberg.org/Conduction/pipelinq; never direct-push
