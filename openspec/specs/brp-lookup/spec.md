# brp-lookup Specification

## Purpose
TBD - created by archiving change pipelinq-brp-via-or-leaf. Update Purpose after archive.
## Requirements
### Requirement: BRP person lookup is OR-first with a behaviour-preserving legacy fallback and an identical Wet-BRP audit trail

The pipelinq BRP person-lookup client (`HaalCentraalClient`) SHALL query the OpenRegister BRP leaf (`GET /apps/openregister/api/integrations/brp/person?bsn=<bsn>`, ADR-022 — connection + credentials in OpenConnector) **first**, via `IClientService` + `IURLGenerator` as an internal, server-side, `OCS-APIREQUEST` + `allow_local_address` call. The elfproef checksum + BSN masking (`BsnValidationService`) SHALL run before any external call regardless of path, and the raw BSN SHALL NEVER be written to the log on either path.

On HTTP 200 with a person the client SHALL map the leaf's raw `results[0]` HAL+JSON person through the EXISTING `normalisePerson()` exactly as before the re-point, and SHALL map the leaf's `meta` into the audit fields the consuming controller persists into the Wet-BRP `brpLookupVerzoek` record: `meta.correlationId → haalcentraalCorrelationId`, `meta.durationMs → responseDuurMs`, `meta.status → responseStatus`. The persisted audit record SHALL be identical in shape and values to the legacy direct-path record for the same upstream data, including persisting a null correlation id as an absent field. On HTTP 200 with zero persons the client SHALL return not-found without calling the legacy path.

When the OR leaf is not usable — it responds 503 with `details.cause` (`openconnector-down` / `openconnector-source-missing` / `provider-auth` / `upstream-service-down`), returns a non-200, or OpenRegister is absent — the client SHALL fall back to its existing OAuth2 + mTLS direct HaalCentraal path **byte-for-byte** (current credentials, certificate, base URL, transport, correlation-id + duration + status derived from the direct response as today), so configured environments keep working until an operator configures + enables the OR `brp-haalcentraal` source. `BsnValidationService` (elfproef + mask), `normalisePerson()`, the `brp.*` OAuth/mTLS configuration, and the legacy transport SHALL be retained as the documented fallback.

**Standards**: ADR-022 (apps consume OpenRegister abstractions; connection + credentials in OpenConnector), Wet BRP (audit trail: correlation id, response duration, response status), RvIG HaalCentraal Personen v2.0
**Feature tier**: V1 (BSN-verified contact enrichment)

#### Scenario: BRP lookup uses the OR leaf with audit metadata when it is available

- **WHEN** the OpenRegister `brp-haalcentraal` source is enabled and a BRP lookup runs after the elfproef passes
- **THEN** `HaalCentraalClient` calls `GET /apps/openregister/api/integrations/brp/person?bsn=…` (internal, OCS-APIREQUEST, allow_local_address), maps the raw `results[0]` through `normalisePerson()`, attaches `meta.correlationId`/`meta.durationMs`/`meta.status` as the audit metadata, and does NOT call the direct HaalCentraal endpoint

#### Scenario: BRP audit record is identical across the OR and legacy transports

- **WHEN** a BRP lookup succeeds via either the OR leaf or the legacy direct path with the same correlation id, duration and status
- **THEN** the persisted `brpLookupVerzoek` record carries the same `haalcentraalCorrelationId`, `responseDuurMs` and `responseStatus`, and a null correlation id is recorded as an absent field on both paths

#### Scenario: BRP lookup falls back to the legacy path when the OR source is dormant

- **WHEN** the OpenRegister `brp-haalcentraal` source returns a 503 (`details.cause` = `openconnector-source-missing`) or OpenRegister is absent
- **THEN** `HaalCentraalClient` falls back to the existing OAuth2 + mTLS direct HaalCentraal path with the configured `brp.*` credentials and certificate, derives the correlation id + duration + status from the direct response, and the audit record is unchanged from before the re-point

#### Scenario: OR-200 with no person is treated as not-found without a legacy call

- **WHEN** the OR leaf returns HTTP 200 with an empty `results` array
- **THEN** `HaalCentraalClient::lookupPersoon` returns null (not-found) and does NOT fall through to the legacy direct path

#### Scenario: the raw BSN is never logged on either the OR or the fallback path

- **WHEN** a BRP lookup runs through the OR-first path or falls back to the legacy path
- **THEN** only the masked BSN appears in any log message or context, the BSN is passed to the OR leaf in the query string only (the leaf places it in the upstream request body and never logs it), and no raw BSN is written to the log on either leg

