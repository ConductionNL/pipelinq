# Design — export-destination credentials fix

## Root cause

`OCA\OpenConnector\Service\SourceService` does not exist in OpenConnector.
Verified directly:

```
$ find openconnector -name SourceService.php    # no results
$ grep -rn "class SourceService" openconnector    # no results
```

`ExportDestinationService::resolveCredentials()` (line ~309) and
`ExportUploadService::resolveCredentials()` (line ~273) both call
`$this->container->get('OCA\OpenConnector\Service\SourceService')`. The
container throws (the service is unregistered); the surrounding
`catch (\Throwable $e)` swallows it; both methods return `[]`. This has been
true since the class was removed — the `method_exists($sourceService,
'getCredentials')` guards inside are dead code that can never execute,
because `$sourceService` itself never resolves.

## The reference fix (commit 96a9d16c) and what generalises

`BlastService::sendOneDelivery()` hit the identical dead-class problem. Its
fix (PR #1163):

1. Resolves the Source object through OpenRegister's published
   `ObjectServiceInterface::find(id, register: 'openconnector', schema:
   'source')` — the register+schema Source objects actually live in today.
2. Dispatches the HTTP call through `OCA\OpenConnector\Service\CallService::
   call()` — a method that genuinely exists and handles the source's
   auth/credentials internally, so `BlastService` itself never touches a raw
   secret value.

Part 1 (locate the Source via OpenRegister, not a dead OpenConnector class)
is the reusable insight and is what this change follows. Part 2 does not
apply here: `ExportDestinationService`/`ExportUploadService` need actual
credential *values* to hand to a sink adapter (S3/SFTP/BigQuery/etc.), not a
single HTTP POST — `CallService::call()` sends one HTTP request to a fixed
endpoint and cannot serve "give me this source's secret" or "PUT this file to
this path over SFTP."

## Why `_render: false`

Investigated directly in `openconnector/lib/Settings/openconnector_register.json`
(lines 150-199): the Source schema's `apikey`/`secret`/`password`/`jwt`
fields are declared `writeOnly: true`. OpenRegister strips every
`x-openregister-writeonly-paths` field on **every rendered read**,
unconditionally — `_rbac: false` does not bring it back (this is documented
directly in OpenConnector's own
`lib/Service/Security/RawSourceResolver.php` docblock, written after
ocon#212 learned it the hard way). Only `_render: false` survives.

`RawSourceResolver::resolveRaw()` is the sanctioned, existing pattern for
this inside OpenConnector itself:

```php
$raw = $this->objectService->find(
    id: $uuid,
    register: 'openconnector',
    schema: 'source',
    _rbac: true,
    _multitenancy: true,
    _render: false
);
```

Its docblock is explicit that this re-read is access-neutral: `_rbac: true`
and `_multitenancy: true` stay on, so nothing about who is allowed to read
the source changes — only whether the write-only fields survive the
serialization boundary. This is not pipelinq's own class to call (it lives
in `OCA\OpenConnector\Service\Security`, an internal, unpublished
OpenConnector namespace — not a contract pipelinq depends on), but pipelinq
already has the exact same tool available: `ObjectServiceInterface`, already
constructor-injected into both `ExportDestinationService` and
`ExportUploadService` via `AbstractExportService`. `resolveCredentials()`
calls it the same way, with the same flags.

## Credential extraction shape

The Source schema (`openconnector_register.json`) exposes two credential
shapes:

- **Legacy inline fields** — `apikey`, `secret`, `password`, `jwt` (plain
  strings, `writeOnly: true`). These are the fields a raw read recovers.
  Note: this path is being actively phased out fleet-wide by OpenConnector's
  own `RemoveMigratedSourceSecretFields` repair step once every source has
  migrated to the credential broker — relying on it is relying on something
  OpenConnector is deliberately deprecating, but it is the only currently
  working path for a directly-extractable secret, and is exactly what
  `RawSourceResolver` itself exists to preserve access to.
- **Brokered credentials** — `configuration.authentication.credentialRef`
  (not `writeOnly`, so it does survive a rendered read, but it is a
  *reference*, not a secret value). Resolving it to an actual secret requires
  `OCA\OpenRegister\Service\Credential\CredentialBrokerService::
  resolveInjectable()`, which is not something pipelinq has a published
  contract for, and which — by design — returns `null` for a host-locked
  proxy credential (the secret architecturally never leaves OpenRegister).
  `resolveCredentials()` surfaces the `configuration.authentication` block
  unchanged (non-secret) so a sink adapter can at least see that a broker
  reference exists, but does not attempt to resolve it to a raw value. A
  destination backed purely by a broker credential still resolves to an
  effectively-empty credentials map for direct-extraction purposes — same
  observable behaviour as today (`[]`), not a regression.

```php
private function extractSourceCredentials(array $source): array {
    $credentials = [];
    foreach (['apikey', 'secret', 'password', 'jwt'] as $field) {
        $value = $source[$field] ?? null;
        if (is_string($value) === true && $value !== '') {
            $credentials[$field] = $value;
        }
    }

    $authentication = $source['configuration']['authentication'] ?? null;
    if (is_array($authentication) === true && $authentication !== []) {
        $credentials['authentication'] = $authentication;
    }

    return $credentials;
}
```

## Why this is implemented locally in each service, not as a new shared helper

`ExportDestinationService` and `ExportUploadService` both extend
`AbstractExportService`, which is also the base of `ExportJobService`.
`AbstractExportService` has no `LoggerInterface` of its own — each subclass
injects its own. Adding a shared `resolveConnectorSourceCredentials()` helper
to `AbstractExportService` would mean either widening its constructor
(rippling into `ExportJobService`, which does not need this and is out of
scope) or silently dropping the existing per-call warning logging. Keeping
the fix local to the two files the bug is actually in — mirroring how the
original (broken) code was already duplicated across both — is the smaller,
safer diff.

## Test approach

The existing `ExportUploadServiceTest` mocks `ObjectServiceInterface` with no
`find()` expectations configured, so `resolveCredentials()` returns `[]` by
construction — this is exactly the shape of test that would hide this bug
(and did: the bug shipped with green tests). New tests configure
`ObjectServiceInterface::find()` to actually return a Source array/entity
with `apikey`/`secret` set, and assert:

1. `find()` is called with `register: 'openconnector'`, `schema: 'source'`,
   `_render: false` (the exact call shape — mirrors
   `BlastServiceTest`'s dedicated assertion for
   `CallService::call()`'s argument shape after the reference fix).
2. The extracted credential values actually reach the sink adapter's
   `testConnection()`/`upload()` call — not mocked away.
3. A source-not-found / OpenRegister-unavailable case still resolves to `[]`
   and fails closed (no exception escapes to the caller), matching current
   behaviour.

No OpenRegister/OpenConnector schema changes. No seed data. No lifecycle,
aggregation, or declarative-behaviour changes — this is a straight service-
method bug fix.
