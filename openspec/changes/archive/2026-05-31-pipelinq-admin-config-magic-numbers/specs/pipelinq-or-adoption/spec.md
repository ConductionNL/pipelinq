# Capability — pipelinq-or-adoption (admin-config magic-numbers slice)

## ADDED Requirements

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
