# brp-lookup Specification

**OpenSpec changes**: [vng-klantinteracties-leaf](../../changes/archive/2026-07-12-vng-klantinteracties-leaf/) _(archived 2026-07-12 — consumes this capability's 11-proef + `brpPersoon.bsnHash` flow for the AVG hash-only handling of VNG `partijIdentificator` BSNs; no requirement of this spec changes)_

## Purpose
An agent looks up a person in the BRP (Basisregistratie Personen) by BSN to fill in or verify a client, through OpenRegister first with the legacy client as a behaviour-preserving fallback. Every lookup leaves the audit trail the Wet BRP asks for, and a lookup returns the person it asked for or nothing.

## Requirements

### Requirement: BRP person lookup is OR-first with a behaviour-preserving legacy fallback and an identical Wet-BRP audit trail

The pipelinq BRP person-lookup client (`HaalCentraalClient`) SHALL query the OpenRegister BRP leaf (`GET /apps/openregister/api/integrations/brp/person?bsn=<bsn>`, ADR-022 — connection + credentials in OpenConnector) **first**, via `IClientService` + `IURLGenerator` as an internal, server-side, `OCS-APIREQUEST` + `allow_local_address` call. The elfproef checksum + BSN masking (`BsnValidationService`) SHALL run before any external call regardless of path, and the raw BSN SHALL NEVER be written to the log on either path.

On HTTP 200 with a person the client SHALL map the leaf's raw `results[0]` HAL+JSON person through the EXISTING `normalisePerson()` exactly as before the re-point, and SHALL map the leaf's `meta` into the audit fields the consuming controller persists into the Wet-BRP `brpLookupVerzoek` record: `meta.correlationId → haalcentraalCorrelationId`, `meta.durationMs → responseDuurMs`, `meta.status → responseStatus`. The persisted audit record SHALL be identical in shape and values to the legacy direct-path record for the same upstream data, including persisting a null correlation id as an absent field. On HTTP 200 with zero persons the client SHALL return not-found without calling the legacy path.

When the OR leaf is not usable — it responds 503 with `details.cause` (`openconnector-down` / `openconnector-source-missing` / `provider-auth` / `upstream-service-down`), returns a non-200, or OpenRegister is absent — the client SHALL fall back to its existing OAuth2 + mTLS direct HaalCentraal path **byte-for-byte** (current credentials, certificate, base URL, transport, correlation-id + duration + status derived from the direct response as today), so configured environments keep working until an operator configures + enables the OR `brp-haalcentraal` source. `BsnValidationService` (elfproef + mask), `normalisePerson()`, the `brp.*` OAuth/mTLS configuration, and the legacy transport SHALL be retained as the documented fallback.

**Standards**: ADR-022 (apps consume OpenRegister abstractions; connection + credentials in OpenConnector), Wet BRP (audit trail: correlation id, response duration, response status), RvIG HaalCentraal Personen v2.0
**Feature tier**: V1 (BSN-verified contact enrichment)

#### Scenario: BRP lookup uses the OR leaf with audit metadata when it is available
@e2e exclude AVG-sensitive server transport: HaalCentraalClientTest::testLookupUsesOpenRegisterLeafWithMeta traps both legacy transports, asserts the OR URL was hit, that the legacy leg was NOT, and the full audit envelope (_correlationId, _responseDurationMs, _responseStatus). Driving a real BSN through a browser is not permissible and the transport choice is not observable in the UI.

- **WHEN** the OpenRegister `brp-haalcentraal` source is enabled and a BRP lookup runs after the elfproef passes
- **THEN** `HaalCentraalClient` calls `GET /apps/openregister/api/integrations/brp/person?bsn=…` (internal, OCS-APIREQUEST, allow_local_address), maps the raw `results[0]` through `normalisePerson()`, attaches `meta.correlationId`/`meta.durationMs`/`meta.status` as the audit metadata, and does NOT call the direct HaalCentraal endpoint

#### Scenario: BRP audit record is identical across the OR and legacy transports
@e2e exclude server-side audit mapping: the OR leg and the null-correlation case are asserted by HaalCentraalClientTest::testLookupUsesOpenRegisterLeafWithMeta / ::testLookupOpenRegisterLeafNullCorrelationId. NOT asserted anywhere: cross-transport equality of the audit record for the same upstream data — that is a genuine PHPUnit gap, not an e2e gap.

- **WHEN** a BRP lookup succeeds via either the OR leaf or the legacy direct path with the same correlation id, duration and status
- **THEN** the persisted `brpLookupVerzoek` record carries the same `haalcentraalCorrelationId`, `responseDuurMs` and `responseStatus`, and a null correlation id is recorded as an absent field on both paths

#### Scenario: BRP lookup falls back to the legacy path when the OR source is dormant
@e2e exclude server transport fallback: HaalCentraalClientTest::testLookupFallsBackToLegacyWhenLeafUnavailable proves the legacy leg is ENTERED (503 unconfigured-OAuth contract). NOT asserted: a configured OAuth2+mTLS call. Unwritten PHPUnit case, not an e2e gap.

- **WHEN** the OpenRegister `brp-haalcentraal` source returns a 503 (`details.cause` = `openconnector-source-missing`) or OpenRegister is absent
- **THEN** `HaalCentraalClient` falls back to the existing OAuth2 + mTLS direct HaalCentraal path with the configured `brp.*` credentials and certificate, derives the correlation id + duration + status from the direct response, and the audit record is unchanged from before the re-point

#### Scenario: OR-200 with no person is treated as not-found without a legacy call
@e2e exclude server transport branch: HaalCentraalClientTest::testLookupOpenRegisterLeafEmptyResultsIsNotFound asserts the 200-empty result is not-found and that no legacy call follows. A browser sees "not found" either way.

- **WHEN** the OR leaf returns HTTP 200 with an empty `results` array
- **THEN** `HaalCentraalClient::lookupPersoon` returns null (not-found) and does NOT fall through to the legacy direct path

#### Scenario: the raw BSN is never logged on either the OR or the fallback path
@e2e exclude log-content assertion with no UI surface: HaalCentraalClientTest::testRawBsnNeverLoggedOnOpenRegisterPath and ::testRawBsnNeverLoggedOnFallbackPath record debug/info/warning/error and assert the BSN appears in no message and in no json_encode($context). Playwright cannot read the server log.

- **WHEN** a BRP lookup runs through the OR-first path or falls back to the legacy path
- **THEN** only the masked BSN appears in any log message or context, the BSN is passed to the OR leaf in the query string only (the leaf places it in the upstream request body and never logs it), and no raw BSN is written to the log on either leg

### Requirement: A BRP lookup can verify a client's identity

A BRP lookup with `verifiedClient` that finds the person MUST record on that client `identityVerifiedAt` (when), `identityVerifiedVia: BRP`, and the `identityCheck` the employee gave. The purpose binding and the audit record MUST be as for any lookup.

#### Scenario: Pieter verifies Sanne in a call
- GIVEN a call with Sanne de Vries, BSN 999994001 in the BRP
- WHEN Pieter looks her up from "Wie is het" with "Geboortedatum en adres gevraagd"
- THEN Sanne's client reads Gecontroleerd "Geboortedatum en adres gevraagd" and Bron "BRP, opgezocht" with today's date

### Requirement: The lookup reads the person it asked for

When the source answers several people, the lookup MUST read the person whose `burgerservicenummer` is the BSN asked for, and the first person when none of them carries it. It MUST read the name from both the English shape (`name`) and HaalCentraal's Dutch shape (`naam`).

#### Scenario: The source answers several people
- GIVEN a BRP answer with two persons, the second carrying `burgerservicenummer` 999993653, the BSN asked for
- WHEN the lookup reads the answer
- THEN it reads the second person

#### Scenario: The Dutch shape
- GIVEN a HaalCentraal answer whose person carries `naam.voornamen` and `naam.geslachtsnaam` and no `name`
- WHEN the lookup reads the answer
- THEN it reads the given names and surname from `naam`
