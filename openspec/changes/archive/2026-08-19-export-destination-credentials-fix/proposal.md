---
kind: code
status: done
archived: 2026-08-19
---

# pipelinq — fix the dead export-destination credentials call

## Why

`ExportDestinationService::resolveCredentials()` and
`ExportUploadService::resolveCredentials()` both do:

```php
$sourceService = $this->container->get('OCA\OpenConnector\Service\SourceService');
if (method_exists($sourceService, 'getCredentials') === true) {
    $credentials = $sourceService->getCredentials($sourceId);
    ...
}
```

`OCA\OpenConnector\Service\SourceService` **does not exist** — confirmed by
`find . -name SourceService.php` returning nothing in `openconnector` and a
repo-wide grep for `class SourceService` returning zero matches. OpenConnector's
`Source`/`Mapping`/`Synchronization`/`Job` entities moved onto OpenRegister's
generic object API months ago. `$this->container->get(...)` throws
(`ContainerExceptionInterface`), the surrounding `try`/`catch (\Throwable)`
swallows it, and both methods silently return `[]` on every call — every
export-destination connectivity test reports "no credentials" and every export
upload resolves an empty credentials map, with no visible error to the admin
who configured the destination.

This is the same class of bug as a bug already fixed in this repo: commit
`96a9d16c` / `95a1ac6d` ("fix(blast): repoint connector source listing + send
path at OpenConnector's current API", PR #1163). `BlastService::sendOneDelivery`
resolved the identical dead `OCA\OpenConnector\Service\SourceService` class; the
fix replaced it with a raw read of the Source object through OpenRegister's
published `ObjectServiceInterface` contract (register `openconnector`, schema
`source`), which is where Source objects actually live now.

### What the reference fix establishes, and where it stops applying

The blast fix's *resolution* half generalises directly: locate the Source
object via `ObjectServiceInterface::find(id, register: 'openconnector', schema:
'source')`, not through a class that no longer exists. This proposal follows
that exact pattern for `resolveCredentials()` in both services.

The blast fix's *dispatch* half — send through
`OCA\OpenConnector\Service\CallService::call()` — does **not** carry over
here, for two independent reasons discovered during investigation, and this
proposal explicitly does not attempt to fix them (see "Known residual
breakage — out of scope" below):

1. **Rendered reads strip every write-only secret field, unconditionally.**
   `ObjectServiceInterface::find()` defaults to `_render: true`, and OpenRegister
   strips every `x-openregister-writeonly-paths` field on any rendered read —
   admins included. Only `_render: false` survives that boundary. OpenConnector's
   own `CallService::resolveSourceForDispatch()` and the dedicated
   `OCA\OpenConnector\Service\Security\RawSourceResolver` class both exist
   specifically to do this raw re-read (`_rbac: true`, `_multitenancy: true`,
   `_render: false` — access-neutral, not a widened read) before touching a
   Source's `apikey`/`secret`/`password`/`jwt` fields. `resolveCredentials()`
   needs the same raw read.
2. **`AbstractOpenConnectorSink` calls two `CallService` methods that have
   never existed.** `probe()` calls `CallService::checkConnection()` and
   `transfer()` calls `CallService::put()` — a full enumeration of
   `CallService`'s ~90 methods (grep across `lib/Service/CallService.php`,
   3482 lines) confirms neither is, or has ever been, declared. Both calls are
   `method_exists()`-guarded and degrade gracefully (`probe()` falls back to
   `$credentials !== []`; `transfer()` throws `RuntimeException('OpenConnector
   CallService does not support file uploads.')`), so today every export
   upload fails unconditionally and every connectivity test is a
   non-diagnostic boolean, independent of whatever `resolveCredentials()`
   returns.

Fixing (2) would mean either extending OpenConnector's `CallService` — a
different repo, and `CallService` is HTTP/Guzzle-only so it could never serve
non-HTTP destination types like SFTP or Postgres — or building real
per-protocol clients (AWS SDK, phpseclib, DB drivers) inside pipelinq itself,
which is a genuine architecture decision (which destination types are even
supported, credential shape per protocol) that deserves its own proposal, not
a silent scope expansion riding on a one-line dead-call fix. This proposal
fixes exactly the named bug — the dead credential *resolution* call — and
documents the residual breakage below so it is not mistaken for fixed.

## What Changes

- `ExportDestinationService::resolveCredentials()` and
  `ExportUploadService::resolveCredentials()` resolve the OpenConnector
  Source via `ObjectServiceInterface::find(id: $sourceId, register:
  'openconnector', schema: 'source', _rbac: true, _multitenancy: true,
  _render: false)` instead of the dead `SourceService::getCredentials()`
  call. `_rbac`/`_multitenancy` stay `true` (access-neutral, matching
  OpenConnector's own `RawSourceResolver`); only the render mode changes.
- Both methods extract the legacy write-only credential fields
  (`apikey`/`secret`/`password`/`jwt` — the only fields the current Source
  schema exposes for direct extraction) plus the non-secret
  `configuration.authentication` block (which may carry a
  `credentialRef` for the broker-vaulted case) into the returned credentials
  map. A `credentialRef`-only source yields no directly usable secret value —
  this is unchanged from today's behaviour (it already returned `[]`) and is
  an accepted limitation, not a regression: extracting a broker-vaulted
  secret as a raw value is architecturally not possible for a host-locked
  proxy credential (`CredentialBrokerService::resolveInjectable()` returns
  `null` by design for those).
- `ExportUploadService::resolveCredentials()` additionally drops its dead
  second branch (`method_exists($sourceService, 'find')` on the
  non-existent `$sourceService`), which was equally unreachable.
- Tests exercise the real credential-resolution path (a mocked
  `ObjectServiceInterface::find()` returning a raw Source array/entity,
  asserting the exact `register`/`schema`/`_render` call shape and that the
  extracted fields reach the sink adapter), not a test that mocks the
  resolution away.

## Known residual breakage — out of scope

`AbstractOpenConnectorSink::probe()` / `::transfer()` call
`CallService::checkConnection()` / `CallService::put()`, neither of which
exists in OpenConnector today. After this fix, `resolveCredentials()` will
correctly surface real credential values when a source has legacy inline
secrets — but:

- **Connection tests remain a non-diagnostic boolean.** `testConnection()`
  will report "valid" whenever *any* credential value came back, regardless
  of whether the destination is actually reachable.
- **Uploads remain unconditionally broken.** Every `uploadFiles()` call still
  throws `RuntimeException('OpenConnector CallService does not support file
  uploads.')` after all 5 retries, for every destination type.

This is a separate, deeper bug one layer below credential resolution,
independent of the `SourceService::getCredentials()` issue this proposal
fixes. It requires either a cross-repo change to OpenConnector's
`CallService` (which is HTTP-only and structurally cannot serve SFTP/Postgres
destinations) or new per-protocol client code inside pipelinq — a genuine
architecture decision, flagged here for a follow-up proposal rather than
folded into this fix.

## Impact

- Affected specs: `bi-export-and-data-warehouse-sink` (new capability spec —
  the archived change `2026-06-14-bi-export-and-data-warehouse-sink` was
  never synced into `openspec/specs/`; this change creates it).
- Affected code: `lib/Service/Export/ExportDestinationService.php`,
  `lib/Service/Export/ExportUploadService.php`.
- Affected tests: `tests/Unit/Service/Export/ExportUploadServiceTest.php`
  (extended), new `tests/Unit/Service/Export/ExportDestinationServiceTest.php`.
