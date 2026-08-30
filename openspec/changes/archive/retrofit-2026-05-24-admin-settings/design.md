# Design — retrofit admin-settings

> **Retrofit change.** Tasks describe retroactive annotation, not new implementation work. The code under specification already exists on `development` (`lib/Service/SettingsService.php::getConfigValue` / `::setConfigValue`).

## Context

The `admin-settings` capability has a comprehensive spec covering the admin panel UI, pipeline/stage CRUD, register-mapping, lead-source/request-channel/prospect settings, and user notification preferences. The coverage scan flagged 2 generic config-accessor methods on `SettingsService` (Bucket 2a) that exist purely to give other services a single entry point for reading/writing Pipelinq-scoped IAppConfig values — they are infrastructure, not feature behavior, but they are externally consumed (e.g. `ProspectDiscoveryService::__construct` resolves register IDs through them) and therefore need a spec to defend against accidental renaming or app-id drift.

## Approach

One REQ — `REQ-AS-110: Generic typed app-config accessor MUST scope every read/write to the Pipelinq APP_ID` — covers both `getConfigValue()` and `setConfigValue()`.

Splitting `get` and `set` into separate REQs would inflate without adding test surface: they're a symmetric paired accessor and the binding contract (every call goes through `Application::APP_ID`) is the same.

## Granularity decisions

- **String-only typed boundary**: the accessor wraps `getValueString`/`setValueString` only. Callers needing typed values coerce on the boundary. Flagged in Notes as a possible future tightening.
- **Named-arg call sites**: observed `getConfigValue(key: 'register')` usage in `ProspectDiscoveryService`. The parameter name is part of the contract — flagged in Notes so a future PHPCS / refactor doesn't silently rename `$key` and break callers.

## Out of scope

- Adding typed `getConfigInt`/`getConfigBool` overloads — that's design work, not annotation.
- Per-user config accessors — those are covered by the user-settings REQs (REQ-AS-075/-080).
- The other ~80 methods on `SettingsService` and friends — they're covered by Bucket 1 annotations or existing REQs.
