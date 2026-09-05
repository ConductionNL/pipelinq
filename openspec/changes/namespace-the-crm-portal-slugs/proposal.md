# Namespace the CRM portal slugs

## Why

`portalAccount` and `portalSession` were each claimed by this app and by
portaliq. A schema slug is global per organisation and `SchemaMapper::find()`
matches `LOWER(slug)`, so whichever row the lookup reached first answered for
both.

portaliq owns the portal, so its slugs stay bare and this app's move.

## Why renamed apart rather than folded

`portalAccount` shares `email`, `displayName`, `lastLoginAt` and `status` with
portaliq's, and an email address identifies a person. That nearly put it in the
consolidate pile alongside `employee` and `administration`.

Reading both settles it. portaliq's is an **identity projection**:
`identityRef`, `identityType`, `subjectRef`, `claims`, `audience` — an OIDC
mapping. This app's is a **local credential store**: `passwordHash`,
`mfaSecret`, `emailVerifyTokenHash`, `passwordResetTokenHash`,
`failedLoginAttempts`, `lockedUntil`.

Those are two different records about one person, not one record. An email is a
contact attribute that half the fleet's schemas carry; unlike a BSN or a KvK
number it does not identify the *record type*. `portalSession` shares only
`expiresAt` and `revoked` and was never in doubt.

## What changes

`portalAccount` becomes `crmPortalAccount` and `portalSession` becomes
`crmPortalSession`. Every quoted occurrence in the app was a slug reference:
camelCase compounds do not collide with ordinary prose the way `contract` and
`booking` do, so there were no decoys to preserve.

The config keys stay `portalAccount_schema` and `portalSession_schema`, pinned
through `SettingsLoadService::SCHEMA_CONFIG_KEYS`.
