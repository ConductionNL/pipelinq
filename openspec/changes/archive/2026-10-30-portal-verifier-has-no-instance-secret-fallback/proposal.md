---
kind: code
---

# Proposal: portal-verifier-has-no-instance-secret-fallback

## Summary

`PortalAssertionVerifier` checks the `X-Portal-Subject` assertion portaliq signs. It read portaliq's dedicated `jwt_signing_secret` and, when that was empty or shorter than 16 characters, fell back to the Nextcloud instance `secret`. Portaliq never signs with the instance secret: it refuses to mint without its dedicated secret (`PortalSessionService`). The fallback could therefore only ever accept a token portaliq did not make, signed with a key that other parts of the instance also use. ADR-054 flagged it. This change removes the fallback: without portaliq's own secret, every assertion is refused.

## Why

Follow-up from the signing-secret fix of 13 and 14 October 2026 (receivers read the sensitive secret through `IAppConfig`). That fix made the dedicated secret readable; the fallback is no longer needed for anything and only widens what a receiver accepts.

## What changes

- `lib/Portal/PortalAssertionVerifier.php`: the secret is portaliq's `jwt_signing_secret` read through `IAppConfig::getValueString('portaliq', 'jwt_signing_secret', '', lazy: false)`, or nothing. The `IConfig` constructor argument is removed.
- Tests: the verifier is built the way the DI container builds it, with an `IConfig` that would serve the instance secret, and a token signed with that secret is refused. The test fails on the old constructor.
