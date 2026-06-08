# StUF-ZKN/BG Adapter

Pipelinq ships a StUF 0310 SOAP adapter that bridges Pipelinq Requests and
Contacts to legacy zaaksystemen (Centric Key2Zaken, Atos PinkRoccade, etc.).
The adapter implements the four core ZKN operations (`creeerZaak`,
`actualiseerZaak`, `geefZaakDetails`, `genereerZaakIdentificatie`),
embeds documents as base64 inside the envelope, persists every round-trip
into an append-only audit log, and isolates failing endpoints with a
circuit breaker.

## Spec

- OpenSpec change: `openspec/changes/stuf-zkn-bg-adapter/`
- Spec: `openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md`

## Architecture

```
Outbound (creeerZaak):
  RequestWorkflow ─StufRequestIntegrationService.registerZaak─▶ StufAdapterService.creeerZaak
                                                              ├─ CircuitBreakerService.checkEndpoint
                                                              ├─ StufEnvelopeBuilder.buildLk01CreeerZaak (with WSSE+mTLS)
                                                              ├─ StufMessageHandler.logOutbound  → StufMessage row
                                                              ├─ StufHttpClient.send             (HTTPS only, mTLS)
                                                              ├─ StufMessageParser.parseBevestiging
                                                              └─ persistRequestMapping            → ZaaksysteemMapping row

Inbound (Bv01 / Lk02 / Fo02):
  Zaaksysteem ─POST /api/stuf/inkomend (WSSE auth)─▶ StufController.inkomend
                                                  ├─ resolve endpoint + verify WSSE
                                                  ├─ StufMessageHandler.logInbound      → StufMessage row
                                                  └─ on Bv01 with crossRefnummer:
                                                       StufMessageHandler.transitionStatus(verzonden → bevestigd)
```

The 503 retry path uses `StufRetryJob` background job (5s, 30s, 2m, 10m
backoff) and reuses the SAME referentienummer for idempotency. After four
consecutive transient failures the circuit opens for 5 minutes and a
needs-input event is raised against every admin user (Nextcloud
Notification).

## Data Model

Three OpenRegister schemas live in `lib/Settings/register.d/85-stuf-zkn-bg-adapter.json`:

| Schema | Purpose |
|---|---|
| `stufEndpoint` | One per zaaksysteem connection (URL, sectormodel, WSSE+mTLS refs, zaaktype-mappings, vrijeBerichten templates). |
| `stufMessage` | Append-only audit log entry per envelope (full XML, HTTP status, duration, retries). |
| `zaaksysteemMapping` | Bidirectional link: pipelinq Request ↔ zaak, Contact ↔ NPS/NNP. |

## Endpoint Configuration

1. Add a StufEndpoint object (admin UI: **Settings → StUF endpoints**).
2. Store the WSSE password and (optionally) the mTLS PEM behind vault references:
   - `occ config:app:set --sensitive pipelinq stuf.vault.<sha256(vault://...)> --value '...'`
3. Toggle `actief: true`.
4. Map `zaaktypeMappings`: `{request_type → zkn:omschrijving}` — required, otherwise
   `creeerZaak` throws `ZaaktypeNotMappedException` BEFORE any transmission.
5. Optional: register `vrijeBerichtenTemplates` (`zetStatus`, `geefBetrokkene`, ...).

## API Surface

| Endpoint | Verb | Auth | Purpose |
|---|---|---|---|
| `/api/stuf/outbound` | POST | Admin | Send a registered vrijBericht. |
| `/api/stuf/inkomend` | POST | PublicPage + WSSE | Receive zaaksysteem notifications. |
| `/api/stuf/endpoints` | GET | Admin | List endpoints with circuit-breaker health. |
| `/api/stuf/messages` | GET | Admin | Query the audit log (`endpointId`, `berichtSoort`, `status`, `limit`). |

## Frontend

- **Settings → StUF endpoints** — list endpoints with health badges (`OK`, `Degraded`, `Circuit open`).
- **Settings → StUF audit log** — query the per-call audit log with filters and a full
  envelope-XML inspection dialog. CSV export available.
- **`StufLinkedZaakBadge.vue`** — drop-in detail-view banner that surfaces a linked zaak
  or betrokkene id; emits `@register` so the host page can trigger registration.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `CIRCUIT_OPEN` returned from `/api/stuf/outbound` | Endpoint has had ≥4 consecutive transient failures | Wait 5 min OR reset via `occ config:app:delete pipelinq stuf.cb.open.<endpointId>` |
| `ZaaktypeNotMappedException` | `request.type` not in endpoint's `zaaktypeMappings` | Edit the endpoint, add the slug → zkn omschrijving. |
| `PayloadTooLargeException` | Attached documents exceed 25 MiB pre-base64 | Strip large attachments OR use DMS-direct URL fallback. |
| `TLS_CERT_LOAD_FAILED` | mTLS vault reference unresolvable | Store the PEM via the vault `storeSecret()` helper or admin tooling. |
| `unauthorized` on inbound endpoint | WSSE UsernameToken mismatch | Verify zaaksysteem-side credentials against the endpoint's `wachtwoordKluisRef`. |
| Outbound stuck in `verzonden` | Bv01 never returned OR network drop | Audit log → inspect retries[]; if all attempts exhausted, status moves to `fout`. |

## VNG Standards Reference

- [StUF 0301 framework](https://www.gemmaonline.nl/index.php/Standaarden/StUF/StUF-0301)
- [StUF-ZKN 0310 sectormodel](https://www.gemmaonline.nl/index.php/Standaarden/StUF/StUF-ZKN_0310)
- [StUF-BG 0310 sectormodel](https://www.gemmaonline.nl/index.php/Standaarden/StUF/StUF-BG_0310)
- [VNG StUF testbed](https://www.vng.nl/) (request access via your gemeente's CISO).
