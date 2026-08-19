# Tasks — export-destination credentials fix

> Scope: `ExportDestinationService::resolveCredentials()` and
> `ExportUploadService::resolveCredentials()` call a dead
> `OCA\OpenConnector\Service\SourceService::getCredentials()`. Fix both to
> resolve the OpenConnector Source via OpenRegister's `ObjectServiceInterface`
> (raw read, `_render: false`), mirroring the reference blast fix (96a9d16c).
> `AbstractOpenConnectorSink`'s dead `CallService::checkConnection()`/`::put()`
> calls are a separate, deeper, cross-repo issue — explicitly out of scope
> (see proposal.md "Known residual breakage").

## 1. Fix ExportDestinationService

- [x] 1.1 Replace the dead `SourceService::getCredentials()` call in `resolveCredentials()` with `ObjectServiceInterface::find(id, register: 'openconnector', schema: 'source', _rbac: true, _multitenancy: true, _render: false)`
- [x] 1.2 Add `extractSourceCredentials()` pulling `apikey`/`secret`/`password`/`jwt` + non-secret `configuration.authentication` from the raw source
- [x] 1.3 Preserve fail-closed behaviour: missing source, OpenRegister unavailable, or empty `connectorSourceId` all still resolve to `[]` with a warning logged

## 2. Fix ExportUploadService

- [x] 2.1 Same replacement as 1.1 in its own `resolveCredentials()`
- [x] 2.2 Same extraction as 1.2
- [x] 2.3 Remove the dead second branch (`method_exists($sourceService, 'find')`) that could never execute

## 3. Tests

- [x] 3.1 New `tests/Unit/Service/Export/ExportDestinationServiceTest.php`: no existing coverage today — add tests for `testConnection()` resolving real credentials through a mocked `ObjectServiceInterface::find()` and passing them to the sink
- [x] 3.2 Assert the exact `find()` call shape (`register: 'openconnector'`, `schema: 'source'`, `_render: false`) in both test files
- [x] 3.3 Extend `ExportUploadServiceTest`: a source with `apikey` set reaches the sink's `upload()` credentials argument (not mocked away)
- [x] 3.4 Cover the fail-closed paths: source not found, OpenRegister throws, empty `connectorSourceId`
- [x] 3.5 Cover a `credentialRef`-only source (no legacy fields) still resolves to a usable-but-secret-free map, not an exception

## 4. Verify

- [x] 4.1 Full unit suite green
- [x] 4.2 `composer check:strict` (phpcs/phpmd/psalm/phpstan) clean on changed files
- [x] 4.3 New capability spec `openspec/specs/bi-export-and-data-warehouse-sink/spec.md` created (archived change was never synced) with REQ-BIE-001/008 folded in plus the credentials-resolution delta
- [x] 4.4 Update stale `@spec openspec/changes/bi-export-and-data-warehouse-sink/...` docblock references in both fixed files to the new canonical spec path
