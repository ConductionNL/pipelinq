# Tasks

- [x] `StoreDescriptor` for `commercial-template`, and a thin `search()` over the engine client
- [x] `install()` writing through `ObjectServiceInterface`, refusing record schemas
- [x] `SchemaSlugMap`, so phpstan proves every allowlisted slug has a config key
- [x] Registry settings with a write-only token
- [x] The Store page, the footer menu entry, and `StoreOutline` in `src/icons.js`
- [x] Tests: the allowlist refusal, the write-only token, the identity strip, and the unconfigured-schema error
- [x] Verify the offline contract against a running instance, not just a mock

## Verified against a running instance

With no registry configured, `/apps/pipelinq/store` renders the
`store-not-configured` note and the three built-in templates. The outcome
string comes from the controller, so the engine's client resolved rather than
the analysis stub, which is never autoloaded at runtime.

`GET /api/store/items` answered `application/json`, not HTML. That is the
assertion worth keeping: pipelinq's SPA catch-all matches `/{path}` with
`.*`, so a route registered after it would be served the Vue shell with a 200
and nothing would appear to be wrong. The store routes sit above it.

## The finding dossiq paid for, applied here up front

dossiq's store shipped with `StoreOutline` missing from `src/icons.js`, and an
unregistered icon name renders NO glyph in the navigation rather than a
fallback, so the entry would have shipped blank. Registered here before the
first build rather than after a gate found it.

## One thing this port does differently

dossiq writes through `ConfiguredRegistryService`, a seam its admin settings
tabs already had. Pipelinq has no such seam: its services resolve the register
through `RegisterResolverService` and the schema through the `<slug>_schema`
app-config key, then call `ObjectServiceInterface::saveObject()` directly, the
way `DefaultQueueService` does.

That difference introduced a failure mode dossiq does not have. A missing
app-config key reads as an EMPTY STRING, and `saveObject()` with an empty
schema writes the object into nothing and returns without complaining. The
install would then report success having stored nothing. REQ-PLQ-STORE-008
exists because of that, and the guard refuses the component rather than
attempting the write.
