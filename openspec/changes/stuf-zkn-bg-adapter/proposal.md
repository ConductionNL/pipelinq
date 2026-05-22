# Proposal: stuf-zkn-bg-adapter

## Problem

Pipelinq is positioned as the modern KCC (Klantcontactcentrum) and request-management application for Dutch municipalities, but cannot integrate with the ~250 municipalities that still run legacy zaaksysteem (case management systems). These systems expose their integration layer over StUF — the Standaard Uitwisselings Formaat (Standard Exchange Format), specifically StUF-ZKN 0310 for cases and StUF-BG 0310 for persons.

Without a StUF adapter:
- Citizen requests cannot be registered as a zaak in the back-office zaaksysteem
- Contact persons cannot be synchronized against BRP-derived persoonsgegevens in the zaaksysteem
- Documents attached to requests cannot be delivered to the zaakbehandelaar's existing workflow
- Pipelinq cannot serve as a modern front-end for municipalities using legacy case management

This blocks adoption in the majority of Dutch gemeenten that have not yet migrated to modern ZGW REST APIs.

## Solution

Implement a StUF adapter that bridges Pipelinq requests and contacts with legacy zaaksystemen over SOAP-over-HTTP. The adapter will:

1. **SOAP envelope construction** — Build valid StUF 0310 message envelopes with proper header (stuurgegevens), namespace declarations, and scope/mutatie attributes
2. **Message patterns** — Support kennisgeving (asynchronous notification) and vraag/antwoord (synchronous query) patterns with proper retry and idempotency
3. **Four core ZKN operations** — creeerZaak, actualiseerZaak, genereerZaakIdentificatie, vrijeBerichten
4. **Document binding** — Embed documents as base64-encoded content with configurable payload ceiling
5. **Endpoint configuration** — Store StufEndpoint profiles per gemeente with authentication (WSSE UsernameToken + mutual TLS)
6. **Per-call audit log** — Persist every envelope sent or received in StufMessage for traceability and compliance
7. **Bidirectional mapping** — Link pipelinq entities to zaaksysteem identifiers; maintain ZaaksysteemMapping for requests ↔ zaak and contacts ↔ betrokkene

## Features

| Feature | Description |
|---------|-------------|
| SOAP 1.1 envelope construction | Build valid StUF 0310 envelopes with stuurgegevens, zender, ontvanger, referentienummer, tijdstipBericht, entiteittype, functie |
| Kennisgeving (async notification) flow | Send Lk01/Lk02/Lk03, await Bv01 bevestiging, retry on transient failures, maintain idempotency |
| Vraag/antwoord (sync query) flow | Send Lv01, wait up to 30s for La01, return typed objects, escalate timeout as needs-input |
| creeerZaak operation | Create zaak from pipelinq Request with zaaktype mapping, Contacts as betrokkenen, Documents as base64 attachments |
| genereerZaakIdentificatie operation | Support both pre-allocation (Du01) and post-allocation strategies per gemeente |
| Document base64 binding | Embed files in StUF envelope with size limit (default 25 MiB pre-base64), validate before send |
| vrijeBerichten extension | Support custom message types (e.g., zetStatus) via registered templates per endpoint |
| Per-call audit log | StufMessage captures full XML envelope, HTTP status, wall-clock duration, cross-references to pipelinq entity |
| Retry with exponential backoff | Transient failures retry at 5s, 30s, 2m, 10m; reuse same referentienummer for idempotency |
| Circuit breaker per endpoint | After 4 failures, open circuit for 5 minutes, surface needs-input event |
| Contact ↔ betrokkene mapping | Maintain bidirectional links, query zaaksysteem before creating new betrokkene to avoid duplicates |
| StufEndpoint configuration | Store connection profiles (URL, SOAP version, sectormodel, authentication, TLS cert) per gemeente |

## Scope

- SOAP 1.1 envelope construction and parsing with StUF 0310 rules
- Kennisgeving (Lk01, Lk02, Lk03, Bv01) and vraag/antwoord (Lv01, La01) message patterns
- Four core ZKN operations: creeerZaak, actualiseerZaak, genereerZaakIdentificatie, vrijeBerichten
- Document attachment via base64 with configurable payload size limit
- Per-call audit log (StufMessage) with full envelope capture
- Bidirectional mapping (ZaaksysteemMapping) for Request ↔ zaak and Contact ↔ betrokkene
- StufEndpoint configuration schema with WSSE + mutual TLS authentication
- Retry logic with exponential backoff and idempotency via referentienummer reuse
- Circuit breaker pattern per endpoint
- XSD validation (baseline, MTOM/SwA out of scope)
- Three persistence entities in OpenRegister: StufEndpoint, StufMessage, ZaaksysteemMapping

## Out of Scope

- MTOM/XOP optimization (SwA/MTOM available via explicit endpoint capability flag, future phase)
- Custom StUF versions (0310 only; ZKN+BG sectormodel only)
- Outbound push notifications from zaaksysteem (inbound kennisgeving reception only)
- Per-gemeente XSD customization (baseline VNG-published XSDs only)
- Manual zaak creation UI in Pipelinq (programmatic API only, no UI forms in V1)
- Zaaksysteem polling/sync (event-driven via inbound kennisgeving only)
