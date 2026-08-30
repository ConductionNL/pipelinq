---
retrofit_extensions:
  - REQ-AS-110
---

## Requirements

### REQ-AS-110: Generic typed app-config accessor MUST scope every read/write to the Pipelinq APP_ID

`SettingsService` MUST expose a pair of generic accessors — `getConfigValue(key, default='')` and `setConfigValue(key, value)` — that wrap `IAppConfig::getValueString()` / `setValueString()` scoped to `Application::APP_ID` (`pipelinq`). All other Pipelinq services (e.g. `ProspectDiscoveryService`) MUST read and write app-scoped configuration through these accessors rather than calling `IAppConfig` directly with a hardcoded app id, so that the app-id binding has a single source of truth.

#### Scenario: Get returns the stored value
- GIVEN the key `register` has been previously written with value `"42"`
- WHEN a caller invokes `getConfigValue(key: 'register')`
- THEN the returned value MUST be `"42"`
- AND `IAppConfig::getValueString()` MUST have been called with app id `pipelinq`

#### Scenario: Get returns the supplied default when key is unset
- GIVEN no value has been stored for the key `client_schema`
- WHEN a caller invokes `getConfigValue(key: 'client_schema', default: 'fallback')`
- THEN the returned value MUST be `"fallback"`

#### Scenario: Get returns empty string when default omitted and key is unset
- GIVEN no value has been stored for the key `unknown_key`
- WHEN a caller invokes `getConfigValue(key: 'unknown_key')`
- THEN the returned value MUST be `""` (empty string — the default-default)

#### Scenario: Set persists the value scoped to APP_ID
- GIVEN a caller invokes `setConfigValue(key: 'lead_schema', value: 'lead-42')`
- WHEN a subsequent reader invokes `getConfigValue(key: 'lead_schema')`
- THEN the returned value MUST be `"lead-42"`
- AND `IAppConfig::setValueString()` MUST have been called with app id `pipelinq`

#### Scenario: Other apps' config is not affected
- GIVEN another Nextcloud app has a key `register` with value `"77"` set under its own app id
- WHEN a Pipelinq caller invokes `setConfigValue(key: 'register', value: 'new-value')`
- THEN only the Pipelinq-scoped `register` MUST change
- AND the other app's `register` MUST remain `"77"`

**Notes**
- The accessor is intentionally string-only (`getValueString` / `setValueString`). Callers that need typed values are expected to coerce on the boundary (e.g. `(int)$settings->getConfigValue('limit', '100')`). A future tightening could expose typed `getConfigInt`/`getConfigBool` overloads, but the current single-pair API matches every observed call site.
- The argument-name `key` is observed in named-arg call sites (e.g. `ProspectDiscoveryService` uses `getConfigValue(key: 'register')`). The signature is `public function getConfigValue(string $key, string $default='')` — renaming the parameters would silently break those callers.
