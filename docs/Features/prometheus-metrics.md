# prometheus-metrics

**Status:** Implemented (declarative via OpenRegister AppHost, ADR-040)

## Overview

Pipelinq exposes application metrics in Prometheus text exposition format at
`GET /api/metrics` and a health probe at `GET /api/health`, for monitoring,
alerting, and operational dashboards.

As of the AppHost adoption (2026-06-16) these endpoints are **declarative**:
the health checks and metric set are defined in `src/manifest.json`
(`observability` block) and executed by OpenRegister's shared AppHost engine
through OpenRegister's portable object/table aggregation layer — there is no
longer any per-app PHP-side JSON aggregation. The URLs are unchanged.

## Endpoints

| Endpoint | Auth | Format | Notes |
|---|---|---|---|
| `GET /apps/pipelinq/api/health` | **Public** | JSON | `{status, app, version, checks}`. `database` failure → HTTP 503; `filesystem` failure → HTTP 200 + `status: degraded`. Safe for unauthenticated infra probes (kube readiness/liveness, uptime monitors). |
| `GET /apps/pipelinq/api/metrics` | **Admin only** (ADR-006) | `text/plain; version=0.0.4` | Prometheus exposition. **Changed:** the endpoint is no longer public — Prometheus scrapers must authenticate as a Nextcloud admin (e.g. via app password / basic auth in the scrape config). |

### Scrape configuration

Because `/api/metrics` is now admin-gated, point your Prometheus job at it with
admin credentials, for example:

```yaml
scrape_configs:
  - job_name: pipelinq
    metrics_path: /apps/pipelinq/api/metrics
    basic_auth:
      username: <admin-user>
      password: <app-password>
    static_configs:
      - targets: ['nextcloud.example.org']
```

## Metrics

| Metric | Labels | Source |
|---|---|---|
| `pipelinq_info` | `version`, `php_version`, `nextcloud_version` | implicit (engine) |
| `pipelinq_up` | — | implicit (engine) |
| `pipelinq_leads_total` | `status`, `pipeline` | `objectCount` register `pipelinq`, schema `lead` |
| `pipelinq_leads_value_total` | `pipeline` | `objectSum` register `pipelinq`, schema `lead`, field `value` |
| `pipelinq_clients_total` | — | `objectCount` register `pipelinq`, schema `client` |
| `pipelinq_contacts_total` | — | `objectCount` register `pipelinq`, schema `contact` |
| `pipelinq_service_requests_total` | `status` | `objectCount` register `pipelinq`, schema `request` |

### Corrected counting semantics

`clients_total` and `contacts_total` are now scoped to the exact `client` /
`contact` schema slug. The previous implementation matched the schema **title**
with `LIKE '%client%'` / `'%contact%'`, which also matched the `zgwClient`
("ZGW Client Credentials") and `contactmoment` schemas and therefore
over-counted. Counts may legitimately drop after this change — the
slug-scoped values are the correct ones.

## Related Files

- Manifest: `src/manifest.json` → `observability`
- Adopter controllers: `lib/Controller/HealthController.php`,
  `lib/Controller/MetricsController.php` (thin subclasses of the AppHost generics)
- Change: `openspec/changes/archive/2026-06-16-adopt-apphost/`
- Engine: `OCA\OpenRegister\AppHost\` (OpenRegister), ADR-040
