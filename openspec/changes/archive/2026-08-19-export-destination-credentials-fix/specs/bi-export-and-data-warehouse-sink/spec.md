# bi-export-and-data-warehouse-sink Specification Delta

> This capability spec does not yet exist under `openspec/specs/` — the
> originating change (`archive/2026-06-14-bi-export-and-data-warehouse-sink`)
> was archived without ever being synced into the canonical specs tree. This
> delta establishes the capability with the two requirements this change
> touches (REQ-BIE-001, REQ-BIE-008), carried forward unchanged from the
> archived text where the archived text was already accurate, plus one new
> requirement (REQ-BIE-010) pinning the credential-resolution mechanism this
> change fixes. The remaining requirements from the archived design
> (REQ-BIE-002 through REQ-BIE-007, REQ-BIE-009) are not part of this delta —
> they belong to files this change does not touch and should be synced
> separately by whoever next verifies them against current code.

## ADDED Requirements

### Requirement: Destination configuration and validation (REQ-BIE-001)

An admin MUST be able to create, edit, and validate export destinations. Each
destination SHALL reference an OpenConnector source for credentials.
Connection MUST be tested before saving.

**Files**: `lib/Service/Export/ExportDestinationService.php`

#### Scenario: Create destination with OpenConnector credentials

- **GIVEN** an admin opens the "Export Destinations" page
- **WHEN** they click "New destination" and fill in: name, type (S3),
  connector source, path template
- **AND** they click "Test Connection"
- **THEN** the system MUST attempt to connect using the referenced
  OpenConnector source's credentials
- **AND** if successful, MUST set `validationStatus = "valid"`,
  `lastValidatedAt = now()`
- **AND** the destination MUST be saveable with status "valid"

#### Scenario: Reject destination if credentials invalid

- **GIVEN** a destination with `validationStatus = "invalid"`
- **WHEN** an admin attempts to enable an export job using this destination
- **THEN** the system MUST prevent job enablement

#### Scenario: Support all destination types

- **GIVEN** a destination type field
- **WHEN** the admin selects a type
- **THEN** the following types MUST be available: `s3`, `azure_data_lake`,
  `gcs`, `bigquery`, `snowflake`, `sftp`, `postgres`

### Requirement: Destination upload with retries (REQ-BIE-008)

Files MUST be uploaded to the destination using OpenConnector credentials.
Upload failures SHALL retry with exponential backoff.

**Files**: `lib/Service/Export/ExportUploadService.php`

#### Scenario: Successful file upload

- **GIVEN** a formatted file and a valid destination
- **WHEN** upload executes
- **THEN** the file manifest entry MUST include path, size, rows, sha256,
  `upload_status = "success"`

#### Scenario: Exponential backoff retries

- **GIVEN** an upload that fails with a transient error
- **WHEN** retry logic executes
- **THEN** the system MUST retry with delays 1s, 2s, 4s, 8s, 16s (5 attempts
  total)
- **AND** if all retries fail, MUST set `upload_status = "failed"` and log
  the error

#### Scenario: Partial upload success

- **GIVEN** a job with multiple files
- **WHEN** some files upload successfully and others fail
- **THEN** the result status MUST be `"partial"`
- **AND** `error_message` MUST list the failed files

### Requirement: Credentials resolve through OpenRegister, not a removed OpenConnector class (REQ-BIE-010)

The OpenConnector `Source` an export destination references is an OpenRegister
object (register `openconnector`, schema `source`), not an entity served by
`OCA\OpenConnector\Service\SourceService` — that class does not exist. Credential
resolution MUST locate the Source through OpenRegister's published
`ObjectServiceInterface`, reading it RAW (`_render: false`) so its write-only
legacy credential fields (`apikey`, `secret`, `password`, `jwt`) survive the
read; a rendered read strips them unconditionally. The raw read MUST keep
`_rbac`/`_multitenancy` enabled — the resolution MUST NOT widen who can read
the source, only whether write-only fields come back once the caller is
already authorized to read it. Failure to resolve the source (not found,
OpenRegister unavailable, empty source reference) MUST fail closed to an
empty credentials map, never throw to the destination/upload caller.

@e2e exclude backend credential-resolution mechanism — covered by PHPUnit
against a mocked `ObjectServiceInterface`; no browser-observable UI
difference (the connectivity-test/upload endpoints already existed and their
request/response shape is unchanged)

#### Scenario: Legacy inline credentials resolve from a raw source read

- **GIVEN** an export destination referencing an OpenConnector source that
  has `apikey` set (a legacy inline credential field)
- **WHEN** the destination's credentials are resolved
- **THEN** the system SHALL call `ObjectServiceInterface::find()` with
  `register: "openconnector"`, `schema: "source"`, and `_render: false`
- **AND** the resolved credentials map SHALL include the `apikey` value

#### Scenario: A missing or unresolvable source fails closed

- **GIVEN** an export destination whose `connectorSourceId` does not resolve
  to any OpenConnector source, or OpenRegister is unavailable
- **WHEN** the destination's credentials are resolved
- **THEN** the system SHALL return an empty credentials map
- **AND** SHALL NOT throw an exception to the caller

#### Scenario: A broker-vaulted source yields no directly extractable secret

- **GIVEN** an export destination referencing a source whose only
  authentication is `configuration.authentication.credentialRef` (no legacy
  inline fields set)
- **WHEN** the destination's credentials are resolved
- **THEN** the resolved credentials map SHALL NOT contain a raw secret value
  for that reference
- **AND** the non-secret `authentication` configuration MAY be included for
  the sink adapter to observe that a broker reference exists
