# Design — Remove dead §4 REST API Authentication

## Context

The §4 "REST API Authentication" admin section is the last survivor of an "API access" cluster on
Pipelinq's admin settings page. The preceding change `remove-or-redundant-admin-settings`
(archived 2026-06-21) already removed the §5 "Objects API Access" map and the §3 "MCP Server
Administration" methods from the same `ApiAuthService`. This change removes the remaining §4
REST-token and OAuth-config surface — which leaves `ApiAuthService` empty and so deletes the class.

## Zero-caller proof (the load-bearing argument)

The deletion is safe because §4 authenticates nothing. Verified by grep over `lib/` + `src/`
(excluding `vendor/`, `node_modules/`):

```
$ grep -rn "validateToken" --include="*.php" --include="*.vue" --include="*.js" lib/ src/
lib/Controller/PortalDocumentController.php:122:  $result = $this->signing->validateToken(token: $token);
lib/Service/ApiAuthService.php:169:               public function validateToken(string $plaintext): bool
lib/Service/Portal/DocumentSigningService.php:119: public function validateToken(string $token): array|string|null
```

- `ApiAuthService::validateToken()` (line 169) is the **only** method that would authenticate a
  generated `api_token_*` value. It has **zero callers**.
- The one external `validateToken` callsite, `PortalDocumentController:122`, calls
  `$this->signing->validateToken(...)` where `$this->signing` is a `DocumentSigningService` — a
  different class, a different method (`array|string|null` return, document-signing token), bound
  to the customer-portal auth domain. It is unrelated to the REST API token.

Therefore `generateToken` / `listTokens` / `revokeToken` issue, list and revoke credentials that
no code path ever checks. They are a credential-management UI over a credential that is never
presented to authenticate a request.

For OAuth, `getOAuthConfig()` / `saveOAuthConfig()` only round-trip the `oauth_*` keys between
`IAppConfig` and the settings form (`SettingsController::index()` → `oauthConfig` →
`Settings.vue`). No service consumes them. The grep hits for "oauth" elsewhere
(`LogiusConnector`, `HaalCentraalClient`, `BrpAdminController`, `RingCentralAdapter`) all use their
own distinct config keys (`brp.oauth_endpoint`, adapter-local secrets) and never read the §4
`oauth_client_id` / `oauth_token_endpoint` / `oauth_*` keys.

## OpenRegister owns the real mechanism (ADR-022)

Authenticated machine-to-machine access to Pipelinq's data is already provided by OpenRegister,
which Pipelinq consumes:

- `OCA\OpenRegister\Db\Consumer` — the persisted API consumer (key/secret, scopes).
- `OCA\OpenRegister\Service\AuthorizationService` — the runtime checker that authenticates a
  request against a registered consumer.
- `/api/consumers` — the surface where consumers are created and managed.

Pipelinq objects are reached through OR's `/api/objects/{register}/{schema}` API, which runs OR's
own authentication and RBAC. A separate, leaf-local token/OAuth store is exactly the kind of
duplicated abstraction ADR-022 forbids: it does not — and cannot, given the zero-caller wiring —
add any enforcement on top of OR.

## Decision

Delete §4 frontend and backend in full, including the now-empty `ApiAuthService` class. Leave the
already-written `api_token_*` / `oauth_*` `IAppConfig` rows in place as inert orphans — they are
read by nothing once the code is gone, and deleting persisted secrets is out of scope for a
code-removal change. Record the OR-ownership rule in the `admin-settings` spec so the section is
not reintroduced.

## Risks / Non-risks

- **Risk: a real consumer of a token/OAuth value exists somewhere.** Mitigated by the grep proof
  above; if any runtime consumer were found the change would have stopped instead of deleting.
- **Non-risk: portal document signing.** `DocumentSigningService::validateToken` is untouched;
  only `ApiAuthService` is removed.
- **Non-risk: other OAuth integrations.** Logius / HaalCentraal / BRP / RingCentral keep their own
  config keys and code paths.
