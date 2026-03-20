---
status: implemented
---

# Prometheus Metrics Endpoint

## Purpose
Expose application metrics in Prometheus text exposition format at `GET /api/metrics` for monitoring, alerting, and operational dashboards. Provide a complementary health check endpoint for container orchestration. Enable CRM-specific observability covering pipeline value, client counts, conversion rates, and OpenRegister integration health.

## Requirements

### Requirement: Prometheus Metrics Endpoint
The system SHALL expose a dedicated metrics endpoint that returns all application metrics in Prometheus text exposition format (version 0.0.4). The endpoint MUST be served at `GET /index.php/apps/pipelinq/api/metrics` and MUST return the `Content-Type: text/plain; version=0.0.4; charset=utf-8` header. The existing `MetricsController` (`lib/Controller/MetricsController.php`) implements this endpoint with gauge metrics; this requirement formalizes the contract and extends it with authentication options for external scrapers.

#### Scenario: Prometheus scrapes metrics endpoint
- **GIVEN** Prometheus is configured to scrape `/index.php/apps/pipelinq/api/metrics` every 15 seconds
- **WHEN** Prometheus sends a GET request to the metrics endpoint
- **THEN** the response MUST return HTTP 200 with `Content-Type: text/plain; version=0.0.4; charset=utf-8`
- **AND** the response body MUST contain valid Prometheus exposition format with `# HELP`, `# TYPE`, and metric lines

#### Scenario: Metrics endpoint requires admin authentication by default
- **GIVEN** a non-admin user or unauthenticated client requests the metrics endpoint
- **WHEN** the request is processed by the Nextcloud controller framework
- **THEN** the response MUST return HTTP 401 or HTTP 403
- **AND** no metric data SHALL be exposed to unauthorized users

#### Scenario: Metrics endpoint supports token-based authentication for scrapers
- **GIVEN** an admin has configured a metrics API token in app settings (`metrics_api_token`)
- **WHEN** a request includes the header `Authorization: Bearer <token>`
- **THEN** the metrics endpoint MUST return metrics without requiring a Nextcloud session
- **AND** requests with invalid tokens MUST receive HTTP 403

#### Scenario: Metrics response completes within SLA
- **GIVEN** the Pipelinq database contains up to 100,000 CRM objects across all schemas
- **WHEN** the metrics endpoint is scraped
- **THEN** the full response MUST be generated within 2 seconds
- **AND** database queries in `MetricsRepository` MUST use indexed columns for aggregation

### Requirement: CRM Entity Count Metrics
The system MUST expose gauge metrics for all core CRM entities: clients, contacts, leads, and service requests. The existing `MetricsController.collectMetrics()` already queries these via `MetricsRepository.countObjectsBySchemaPattern()` and `getLeadCounts()`; this requirement formalizes the metric names, labels, and expected behavior.

#### Scenario: Client and contact totals
- **GIVEN** the Pipelinq register contains 250 clients and 430 contacts
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_clients_total 250` and `pipelinq_contacts_total 430` MUST be present
- **AND** both metrics MUST have `# TYPE pipelinq_clients_total gauge` and `# TYPE pipelinq_contacts_total gauge` annotations

#### Scenario: Lead counts by status and pipeline
- **GIVEN** the system contains 40 leads in status "new" in pipeline "sales" and 15 leads in status "won" in pipeline "sales"
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `pipelinq_leads_total{status="new",pipeline="sales"} 40`
  - `pipelinq_leads_total{status="won",pipeline="sales"} 15`

#### Scenario: Service request counts by status
- **GIVEN** the system contains 80 requests in status "open" and 120 in status "closed"
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `pipelinq_service_requests_total{status="open"} 80`
  - `pipelinq_service_requests_total{status="closed"} 120`

#### Scenario: Entity counts update after CRUD operations
- **GIVEN** `pipelinq_clients_total` is 250
- **WHEN** 5 new clients are created via the OpenRegister API and the metrics endpoint is scraped
- **THEN** `pipelinq_clients_total` MUST report 255

### Requirement: Pipeline Value Metrics
The system MUST expose gauge metrics for aggregate pipeline value in EUR, broken down by pipeline name. The existing `MetricsRepository.getLeadValueByPipeline()` provides the data; this requirement formalizes the metric format and ensures financial precision.

#### Scenario: Pipeline value by pipeline name
- **GIVEN** pipeline "enterprise" has a total lead value of EUR 150,000.50 and pipeline "smb" has EUR 42,300.00
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `pipelinq_leads_value_total{pipeline="enterprise"} 150000.5`
  - `pipelinq_leads_value_total{pipeline="smb"} 42300`

#### Scenario: Pipeline value excludes lost leads
- **GIVEN** pipeline "sales" has leads valued at EUR 100,000 in status "won" and EUR 30,000 in status "lost"
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_leads_value_total{pipeline="sales"}` MUST include all leads regardless of status (the full pipeline is tracked)
- **AND** status-specific filtering SHOULD be achievable by querying `pipelinq_leads_total` labels

### Requirement: Lead Conversion Rate Metrics
The system MUST expose a gauge metric representing the lead conversion rate per pipeline, calculated as the ratio of won leads to total leads (excluding leads still in progress). This metric enables sales performance monitoring and forecasting.

#### Scenario: Conversion rate calculation
- **GIVEN** pipeline "sales" has 60 total leads, of which 15 are "won" and 10 are "lost" (35 still active)
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_conversion_rate{pipeline="sales"}` MUST report `0.6` (15 won / 25 resolved)
- **AND** if no leads have been resolved, the metric MUST report `0`

#### Scenario: Conversion rate with empty pipeline
- **GIVEN** pipeline "new-vertical" has 0 leads
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_conversion_rate{pipeline="new-vertical"}` MUST report `0` (not NaN or absent)

### Requirement: API Latency and Request Tracking
The system MUST track HTTP request counts and response time distribution for all Pipelinq API endpoints. Since Pipelinq is a thin client that delegates CRUD to OpenRegister, these metrics focus on the Pipelinq-specific endpoints (settings, configuration, metrics, health).

#### Scenario: HTTP request counter with labels
- **GIVEN** 200 GET requests to `/api/metrics` returned HTTP 200 and 5 GET requests to `/api/health` returned HTTP 200
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `pipelinq_requests_total{method="GET",endpoint="/api/metrics",status="200"} 200`
  - `pipelinq_requests_total{method="GET",endpoint="/api/health",status="200"} 5`

#### Scenario: Request duration histogram with standard buckets
- **GIVEN** API requests have been processed with varying latencies
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_request_duration_seconds` histogram MUST be present with bucket boundaries at 0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0 seconds
- **AND** each bucket MUST carry `method` and `endpoint` labels

#### Scenario: Error counter by type
- **GIVEN** 3 database errors and 2 validation errors have occurred during Pipelinq operations
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_errors_total{type="database"} 3` and `pipelinq_errors_total{type="validation"} 2`

### Requirement: Health Check Endpoint
The system MUST expose a JSON health check endpoint at `GET /index.php/apps/pipelinq/api/health` that reports the status of all critical subsystems. The existing `HealthController` (`lib/Controller/HealthController.php`) checks database and filesystem; this requirement formalizes the contract and extends it with OpenRegister dependency checking.

#### Scenario: All checks pass
- **GIVEN** the database is accessible and the filesystem is writable
- **WHEN** `GET /api/health` is requested
- **THEN** HTTP 200 with `{"status": "ok", "version": "1.2.0", "checks": {"database": "ok", "filesystem": "ok"}}`

#### Scenario: Database failure produces error status
- **GIVEN** the database connection has been lost
- **WHEN** `GET /api/health` is requested
- **THEN** HTTP 503 with `{"status": "error", "checks": {"database": "failed: Connection refused", "filesystem": "ok"}}`
- **AND** `pipelinq_up` gauge MUST be set to 0 on the next metrics scrape

#### Scenario: Filesystem failure produces degraded status
- **GIVEN** the temp directory is not writable but the database is healthy
- **WHEN** `GET /api/health` is requested
- **THEN** HTTP 200 with `{"status": "degraded", "checks": {"database": "ok", "filesystem": "failed: cannot write to temp directory"}}`

#### Scenario: Health check is usable by container orchestrators
- **GIVEN** a Kubernetes or Docker deployment with liveness probes configured
- **WHEN** the orchestrator sends `GET /api/health` at regular intervals
- **THEN** HTTP 200 indicates the container is healthy; HTTP 503 triggers a restart

### Requirement: OpenRegister Integration Health
The system MUST verify connectivity to the OpenRegister app, which is the data layer for all Pipelinq entities. Since Pipelinq owns no database tables and delegates all CRUD to OpenRegister, a failure in OpenRegister renders Pipelinq non-functional.

#### Scenario: OpenRegister app is installed and enabled
- **GIVEN** the OpenRegister app is installed and enabled in Nextcloud
- **WHEN** the health endpoint checks dependencies
- **THEN** `checks.openregister` MUST report `"ok"`
- **AND** `pipelinq_dependency_up{dependency="openregister"} 1`

#### Scenario: OpenRegister app is disabled
- **GIVEN** the OpenRegister app has been disabled by an admin
- **WHEN** the health endpoint checks dependencies
- **THEN** `checks.openregister` MUST report `"unavailable: app disabled"`
- **AND** `pipelinq_dependency_up{dependency="openregister"} 0`
- **AND** the overall status MUST be `"error"` (Pipelinq cannot function without OpenRegister)

#### Scenario: OpenRegister register not configured
- **GIVEN** OpenRegister is enabled but the Pipelinq register has not been imported via `ConfigurationService`
- **WHEN** the health endpoint checks the register configuration
- **THEN** `checks.register_configured` MUST report `"missing"` and overall status MUST be `"degraded"`

### Requirement: Standard Application Info Metrics
Every Pipelinq deployment MUST expose a baseline set of info and health gauge metrics consistent with the shared Conduction app pattern (OpenRegister, OpenCatalogi, Procest).

#### Scenario: Application info gauge
- **GIVEN** Pipelinq version 1.2.0 is running on PHP 8.2.15
- **WHEN** the metrics endpoint is scraped
- **THEN** the response MUST include:
  - `pipelinq_info{version="1.2.0",php_version="8.2.15"} 1`

#### Scenario: Application health gauge reflects health endpoint
- **GIVEN** the health endpoint reports status "degraded"
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_up` MUST be `0`
- **AND** operators can correlate with `GET /api/health` for details

### Requirement: Metrics Export Format Compliance
All metrics MUST strictly comply with the Prometheus text exposition format and the OpenMetrics specification. The existing `MetricsFormatter` (`lib/Service/MetricsFormatter.php`) handles formatting; this requirement ensures edge cases are handled correctly.

#### Scenario: Label values are properly escaped
- **GIVEN** a pipeline name contains special characters like `"enterprise \"premium\""` or newlines
- **WHEN** the metrics endpoint renders this label
- **THEN** the label MUST be escaped per Prometheus spec: backslashes doubled, quotes escaped, newlines as `\n`
- **AND** the existing `MetricsFormatter.sanitizeLabel()` method MUST handle all three escape cases

#### Scenario: Empty result sets produce valid output
- **GIVEN** there are no leads in the system (empty CRM)
- **WHEN** the metrics endpoint is scraped
- **THEN** `pipelinq_leads_total` MUST still be present with a `# HELP` and `# TYPE` line but no data lines
- **AND** `pipelinq_clients_total 0` and `pipelinq_contacts_total 0` MUST be present

#### Scenario: Metrics output ends with newline
- **GIVEN** the `MetricsController.collectMetrics()` concatenates all metric lines
- **WHEN** the final output is generated
- **THEN** the response body MUST end with a trailing newline character (`\n`)

### Requirement: Alerting Threshold Configuration
The system MUST support configurable alerting thresholds that generate Nextcloud notifications when CRM-specific operational bounds are exceeded. These thresholds help operations teams detect anomalies in CRM usage patterns.

#### Scenario: Error rate threshold notification
- **GIVEN** the admin has configured an error rate threshold of 5% over 5 minutes
- **WHEN** the error rate exceeds 5% (e.g., 6 out of 100 requests return 5xx)
- **THEN** a Nextcloud notification MUST be sent to admin users
- **AND** the condition MUST be queryable as `pipelinq_error_rate_exceeded 1`

#### Scenario: Pipeline value drop alert
- **GIVEN** the admin has configured a pipeline value drop threshold of 20% day-over-day
- **WHEN** the total pipeline value drops from EUR 500,000 to EUR 380,000
- **THEN** a Nextcloud notification SHOULD be sent to admin users with the percentage drop

### Requirement: Metrics Retention and Storage
Since PHP is request-scoped, counter and histogram data MUST be persisted across requests. For Pipelinq's current scope (gauge-only CRM metrics queried from OpenRegister), this is achieved by live database queries. Future counter/histogram metrics MUST use a durable storage mechanism.

#### Scenario: Gauge metrics are computed live from OpenRegister
- **GIVEN** the `MetricsRepository` queries `openregister_objects` and `openregister_schemas` tables
- **WHEN** the metrics endpoint is scraped
- **THEN** all gauge metrics (clients, contacts, leads, values) MUST reflect the current database state
- **AND** no separate metrics storage table is needed for gauge-only metrics

#### Scenario: Future counter storage uses database persistence
- **GIVEN** `pipelinq_requests_total` and `pipelinq_errors_total` are counter metrics that must survive process restarts
- **WHEN** these counters are implemented
- **THEN** they MUST be stored in a durable store (database or shared cache)
- **AND** they MUST NOT rely solely on PHP process memory or APCu (which is cleared on restart)

### Requirement: Nextcloud Dashboard Widget for CRM Metrics
The system MUST register an `OCP\Dashboard\IAPIWidget` that displays key CRM metrics on the Nextcloud dashboard home screen. This provides at-a-glance visibility into CRM health without requiring Grafana or external tooling.

#### Scenario: Dashboard widget shows CRM summary
- **GIVEN** an authenticated user views the Nextcloud dashboard
- **WHEN** the Pipelinq widget is enabled
- **THEN** the widget MUST display: total clients, total leads, total pipeline value, and lead conversion rate

#### Scenario: Dashboard widget links to Pipelinq
- **GIVEN** the user sees the CRM summary widget
- **WHEN** the user clicks the widget
- **THEN** the system MUST navigate to the Pipelinq app dashboard view

### Requirement: Multi-tenant Metrics Isolation
When multiple Nextcloud instances share the same monitoring infrastructure, metrics MUST be distinguishable by instance. The system MUST support an instance identifier label to prevent metric collision.

#### Scenario: Instance label on all metrics
- **GIVEN** the admin has configured `metrics_instance_id` as `"gemeente-amsterdam"`
- **WHEN** the metrics endpoint is scraped
- **THEN** all metrics MUST include an `instance` label: e.g., `pipelinq_clients_total{instance="gemeente-amsterdam"} 250`

#### Scenario: Default instance label from Nextcloud
- **GIVEN** no explicit `metrics_instance_id` is configured
- **WHEN** the metrics endpoint is scraped
- **THEN** the `instance` label MUST default to the Nextcloud instance ID from `OC_Util::getInstanceId()` or be omitted (Prometheus adds its own `instance` label from the scrape target)

### Requirement: Grafana Dashboard Templates
The system MUST provide a Grafana dashboard JSON template that can be imported to visualize all Pipelinq metrics. The template enables operations teams to get production-ready dashboards without manual configuration.

#### Scenario: Dashboard template covers all CRM metrics
- **GIVEN** an operations team imports the Grafana dashboard JSON from the Pipelinq repository
- **WHEN** the dashboard is loaded in Grafana with the Prometheus data source
- **THEN** the dashboard MUST include panels for: client/contact counts, pipeline value over time, lead conversion rate, lead status distribution, service request volume, API error rate, and health status

#### Scenario: Dashboard template uses standard variables
- **GIVEN** the Grafana dashboard template is imported
- **WHEN** the user configures the dashboard
- **THEN** the template MUST use Grafana variables for: `$datasource` (Prometheus data source), `$instance` (target instance), and `$pipeline` (pipeline filter)

## Current Implementation Status
- **Implemented -- Prometheus metrics endpoint**: `MetricsController` (`lib/Controller/MetricsController.php`) exposes `/api/metrics` with `pipelinq_info`, `pipelinq_up`, `pipelinq_leads_total` (by status/pipeline), `pipelinq_leads_value_total` (by pipeline), `pipelinq_clients_total`, `pipelinq_contacts_total`, and `pipelinq_service_requests_total` (by status) gauges. Content-Type header is correctly set to Prometheus exposition format.
- **Implemented -- health check endpoint**: `HealthController` (`lib/Controller/HealthController.php`) exposes `/api/health` with database and filesystem checks, returning `ok`/`degraded`/`error` status with HTTP 200/503.
- **Implemented -- metrics repository**: `MetricsRepository` (`lib/Service/MetricsRepository.php`) provides database queries for lead counts, lead values, object counts by schema pattern, and request counts using OpenRegister's `openregister_objects` and `openregister_schemas` tables.
- **Implemented -- metrics formatter**: `MetricsFormatter` (`lib/Service/MetricsFormatter.php`) formats all metric types (info, leads, values, gauges, requests) into Prometheus text exposition format with proper label sanitization.
- **Implemented -- route registration**: Routes for `/api/metrics` (GET) and `/api/health` (GET) are registered in `appinfo/routes.php`.
- **Not implemented -- token-based metrics authentication**: No support for `Authorization: Bearer <token>` access to the metrics endpoint for external scrapers.
- **Not implemented -- OpenRegister dependency check**: `HealthController` does not verify that the OpenRegister app is enabled or that the Pipelinq register is configured.
- **Not implemented -- conversion rate metric**: No `pipelinq_conversion_rate` gauge is computed or exposed.
- **Not implemented -- HTTP request counters and histograms**: No middleware tracks per-request duration as histogram data or increments request counters with method/endpoint/status labels.
- **Not implemented -- error counters**: No `pipelinq_errors_total` counter by error type.
- **Not implemented -- alerting thresholds**: No configurable threshold system with Nextcloud notifications.
- **Not implemented -- Nextcloud dashboard widget**: No `IAPIWidget` registration for CRM metrics on the Nextcloud dashboard.
- **Not implemented -- Grafana dashboard template**: No JSON template provided in the repository.
- **Not implemented -- multi-tenant instance labels**: No configurable instance identifier on metrics.

## Standards & References
- Prometheus text exposition format: https://prometheus.io/docs/instrumenting/exposition_formats/
- OpenMetrics specification: https://openmetrics.io/
- Kubernetes health check conventions: `/health` (liveness), `/ready` (readiness)
- Nextcloud dashboard widgets: `OCP\Dashboard\IAPIWidget`
- Nextcloud server monitoring: `/ocs/v2.php/apps/serverinfo/api/v1/info`
- OpenRegister production-observability spec: shared patterns for MetricsController, HealthController, MetricsService
- Shared pattern: `opencatalogi`, `openregister`, `procest` prometheus-metrics specs follow the same structural pattern
- Cross-reference: OpenRegister `MetricsService` for counter persistence patterns (APCu + database flush)
- Grafana dashboard provisioning: https://grafana.com/docs/grafana/latest/dashboards/build-dashboards/import-dashboards/

## Specificity Assessment
Highly specific -- metric names, types, labels, and endpoint contracts are fully defined. Implementation is partially complete with MetricsController, MetricsRepository, MetricsFormatter, and HealthController already in place. Remaining work focuses on conversion rate calculation, request/error counters, OpenRegister dependency health, authentication options, and dashboard integration.
