## MODIFIED Requirements

### Requirement: Company lookups are OR-first with a behaviour-preserving legacy fallback

The pipelinq company-lookup clients (`KvkApiClient`, `OpenCorporatesApiClient`) SHALL query the OpenRegister company-lookup leaves (`GET /apps/openregister/api/integrations/kvk/search`, `/opencorporates/search`, ADR-022 — connection + credentials in OpenConnector) **first**, via `IClientService` + `IURLGenerator` as an internal, server-side, `OCS-APIREQUEST` + `allow_local_address` call. On HTTP 200 the client SHALL map the leaf's raw upstream rows (`results`) through the EXISTING `KvkResultMapper` / `OpenCorporatesResultMapper` exactly as before the re-point, so the OR-200 output is identical to the legacy output for the same upstream data.

When the OR leaf is not usable — it responds 503 with `details.cause` (`openconnector-down` / `openconnector-source-missing` / `upstream-service-down`), returns a non-200, or OpenRegister is absent — the client SHALL fall back to its existing direct vendor path **byte-for-byte** (current credentials, base URL, transport), so configured environments keep working until an operator configures + enables the OR source. The existing mappers, the `pipelinq.kvk.*` / `pipelinq.opencorporates.*` configuration, and the legacy transport methods SHALL be retained as the documented fallback.

BRP (`HaalCentraalClient`) SHALL NOT be re-pointed by this change: the OR `/brp/person` leaf does not relay the upstream correlation id or response timing that the Wet-BRP `brpLookupVerzoek` audit trail requires, so an OR-first BRP path would not be behaviour-identical. `HaalCentraalClient`, `BsnValidationService` (elfproef + mask), and `normalisePerson()` SHALL be left unchanged.

**Standards**: ADR-022 (apps consume OpenRegister abstractions; connection + credentials in OpenConnector), KvK Handelsregister Zoeken, OpenCorporates v0.4
**Feature tier**: V1 (prospect discovery / company enrichment)

#### Scenario: KvK lookup uses the OR leaf when it is available

- **WHEN** the OpenRegister `kvk` source is enabled and a KvK SBI search runs
- **THEN** `KvkApiClient` calls `GET /apps/openregister/api/integrations/kvk/search?sbiHoofdActiviteit=…` (internal, OCS-APIREQUEST, allow_local_address), maps the raw `results` rows through `KvkResultMapper`, and does NOT call the direct `api.kvk.nl/zoeken` endpoint

#### Scenario: KvK lookup falls back to legacy when the OR source is dormant

- **WHEN** the OpenRegister `kvk` source returns a 503 (`details.cause` = `openconnector-source-missing`) or OpenRegister is absent
- **THEN** `KvkApiClient` falls back to the existing direct `/zoeken` path with the configured `pipelinq.kvk.*` credentials and base URL, and the mapped output is unchanged from before the re-point

#### Scenario: OpenCorporates lookup uses the OR leaf when available, else legacy

- **WHEN** an OpenCorporates keyword search runs
- **THEN** `OpenCorporatesApiClient` calls `GET /apps/openregister/api/integrations/opencorporates/search?q=…` first and maps the raw `results` companies through `OpenCorporatesResultMapper`; on a 503 / OR-absent it falls back to the direct `companies/search` path byte-for-byte

#### Scenario: BRP is not re-pointed and keeps its audit trail intact

- **WHEN** a BRP lookup runs through `BrpController::lookup`
- **THEN** it still calls `HaalCentraalClient::lookupPersoon` over the existing OAuth2 + mTLS path (not the OR leaf), the elfproef runs before any external call, the BSN is never logged, and the `brpLookupVerzoek` audit record still carries the HaalCentraal correlation id and response duration

#### Scenario: OR-first re-point introduces no new logging of secrets

- **WHEN** any company lookup goes through the OR-first path
- **THEN** the request carries no end-user-controlled URL host (SSRF-safe, internal endpoint only) and no API key or BSN is written to the log on either the OR or the fallback leg
