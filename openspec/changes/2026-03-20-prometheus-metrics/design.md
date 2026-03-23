# Design: Prometheus Metrics Enhancements

## Architecture
Extends existing controller/service pattern. No new classes needed.

## Changes

### MetricsController
- Add `@PublicPage` annotation + manual token check via `Authorization: Bearer` header
- Read `metrics_api_token` from IAppConfig; if set and request has valid Bearer token, allow access
- If no token configured, require admin auth (existing behavior)

### HealthController
- Add `checkOpenRegister()` method using IAppManager to verify openregister is installed/enabled
- Add `checkRegisterConfigured()` using IAppConfig to verify register ID is set
- Include both checks in health response

### MetricsRepository
- Add `getConversionRates()` method: queries leads grouped by pipeline, counting won vs resolved (won+lost)
- Returns array of `{pipeline, won, resolved}` rows

### MetricsFormatter
- Add `formatConversionRates(array $rates)` method
- Add `formatDependencyUp(string $name, bool $up)` method
