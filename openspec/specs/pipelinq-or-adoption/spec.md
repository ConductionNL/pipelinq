---
status: done
---

# Pipelinq OpenRegister-Adoption Specification

## Purpose

Track the cross-cutting work that aligns pipelinq with the shared OpenRegister
abstractions and multi-tenant deployment model. This slice covers Phase 7
(admin-config) and Phase 8 (manifest adoption). Phase 7 moves hardcoded
constants (background-job timing, business hours, third-party API base URLs,
cache TTL, default review intervals) into admin-config so they are tunable per
deployment without code changes, while preserving current behavior for any
install that leaves them unconfigured. Phase 8 ships the OpenSpec coordination
manifest (`openspec/manifest.yaml`) declaring pipelinq's tier, OpenRegister
dependency, the shared specs it consumes, the minimum OR version, and its
object-store-exemplar role. The Phase 9 multi-tenancy + i18n runtime-adoption
consumes are declared in the manifest; their runtime adoption is deferred until
the nc-vue / OpenRegister prerequisites ship.

@e2e exclude pure backend/config-adoption slice: admin-config constant migration (PHP services/BackgroundJob), openspec/manifest.yaml declarations, createObjectStore tenant-context wiring, and Accept-Language API negotiation — no UI surface; covered by PHPUnit, manifest assertions, and Newman.

## Requirements

### Requirement: Tenant-tunable values move to admin-config

Hardcoded constants flagged in `.claude/audit-2026-05-03/04-hardcoded.md` SHALL move to
admin-config. Default values SHALL preserve current behavior.

#### Scenario: Background-job intervals are admin-config

- **GIVEN** an admin sets `pipelinq.task_expiry.poll_interval_seconds = 1800`
- **WHEN** `TaskExpiryJob` runs
- **THEN** the poll interval SHALL be 1800
- **AND** no `INTERVAL = 900` constant SHALL exist in `lib/BackgroundJob/TaskExpiryJob.php`.

#### Scenario: Business hours are tenant-tunable

- **GIVEN** an admin sets
  `pipelinq.task.business_hour_start = 9` and `pipelinq.task.business_hour_end = 18`
- **WHEN** `TaskService` evaluates whether a moment is within business hours
- **THEN** business hours SHALL be 09:00-18:00 in the tenant's configured timezone
- **AND** no `BUSINESS_HOUR_START = 8` or `BUSINESS_HOUR_END = 17` constant SHALL exist
  in `lib/Service/TaskService.php`.

#### Scenario: Third-party API base URLs are admin-config

- **GIVEN** an admin sets `pipelinq.kvk.api_base_url` to a regional endpoint
- **WHEN** `KvkApiClient` makes a request
- **THEN** the request SHALL go to the configured URL
- **AND** the constant `API_BASE` SHALL no longer exist in
  `lib/Service/KvkApiClient.php`.

#### Scenario: Defaults preserve current behavior

- **GIVEN** a fresh pipelinq install with no admin-config overrides
- **WHEN** any service reads a value migrated under Phase 7
- **THEN** the value SHALL equal the constant value listed in
  `.claude/audit-2026-05-03/04-hardcoded.md`.

### Requirement: pipelinq declares its manifest

pipelinq SHALL ship `openspec/manifest.yaml` declaring `tier: 3` (frontend exemplar),
`dependencies: ["openregister"]`, the consumed shared specs, the minimum OR version, and
its exemplar role.

#### Scenario: Manifest declares exemplar role

- **GIVEN** `openspec/manifest.yaml` declares
  `pipelinq.role: object-store-exemplar` (or equivalent key from `adopt-app-manifest`)
- **WHEN** Hydra coordination loads the manifest
- **THEN** it SHALL recognize pipelinq as the reference implementation.

#### Scenario: Manifest pins minimum OR version including contacts-actions

- **GIVEN** the spec-rewrites slice depends on the `contacts-actions` provider in OR
- **WHEN** the manifest declares minimum OR version
- **THEN** the version pin SHALL include the OR release that ships
  `contacts-actions`.

### Requirement: pipelinq consumes shared multi-tenancy + i18n specs

pipelinq SHALL consume `multi-tenancy-context`, `i18n-source-of-truth`, and
`i18n-api-language-negotiation`.

#### Scenario: createObjectStore receives tenant context explicitly

- **GIVEN** the nc-vue `multi-tenancy-context` composable is available
- **WHEN** `src/store/modules/object.js` invokes `createObjectStore('object', {...})`
- **THEN** the factory call SHALL pass the tenant context from `useTenantContext()`
  explicitly (formalising the implicit dependency).

#### Scenario: API respects Accept-Language

- **GIVEN** a client sends `Accept-Language: nl-NL` to pipelinq
- **WHEN** the response includes a translatable label or description
- **THEN** the field SHALL return the Dutch translation per OR's negotiation spec.
