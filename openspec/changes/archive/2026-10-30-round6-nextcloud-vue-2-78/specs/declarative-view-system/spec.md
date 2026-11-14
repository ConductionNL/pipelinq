# Delta: declarative-view-system

## ADDED Requirements

### Requirement: Persisted overrides are only requested from an installed buildiq (REQ-DVS-OVERRIDE-PROBE)
At boot pipelinq MUST ask buildiq for persisted manifest overrides only when
buildiq (or its old app id openbuild) is enabled for the current user. Without
it, pipelinq MUST use the bundled manifest and MUST NOT send the request.

#### Scenario: buildiq is not installed
@e2e exclude Asserted in tests/vitest/persistedOverrides.spec.js with the library's real useAppStatus; checked live on :8099.
- **GIVEN** an instance where buildiq and openbuild are not enabled
- **WHEN** a user opens any pipelinq page
- **THEN** no request MUST go to `/apps/buildiq/api/app-overrides/pipelinq`
- **AND** the page MUST render from the bundled manifest

#### Scenario: buildiq is installed
@e2e exclude Asserted in tests/vitest/persistedOverrides.spec.js.
- **GIVEN** an instance where buildiq is enabled and holds an override for pipelinq
- **WHEN** a user opens any pipelinq page
- **THEN** pipelinq MUST request `/apps/buildiq/api/app-overrides/pipelinq` once
- **AND** MUST apply the returned delta over the bundled manifest
