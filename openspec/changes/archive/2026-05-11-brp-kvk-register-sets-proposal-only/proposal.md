# Proposal: BRP/KVK Register Sets for CRM Clients

## Status
PROPOSED

## Problem
CRM clients in Pipelinq are free-form objects with no link to base registries for person or company data. There is no way to enrich client records with authoritative data from the BRP (Basisregistratie Personen) or KVK (Kamer van Koophandel) registries, and no structured distinction between person, company, and contact client types.

## Solution
Create BRP person and KVK company register schemas in OpenRegister with test seed data. Add a client type selector (Person / Company / Contact) when creating clients. Enable search across BRP, KVK, and Nextcloud Contacts to populate client records from authoritative sources.

## Features
- **BRP person register schema** (BSN, name, address, birthdate) -- shared with Procest
- **KVK company register schema** (KVK number, trade name, legal form, address) -- shared with Procest
- **Test seed data** (10 persons, 10 companies) for development and demo purposes
- **Client type selector** in client creation dialog (Person / Company / Contact)
- **Cross-source search** across BRP, KVK, and Nextcloud Contacts when creating or linking clients
- **Client type and registry reference** stored on client object (type, source registry, source ID)
- **Registry details display** on client detail view showing authoritative data alongside CRM data

## Standards
- Haal Centraal BRP Personen Bevragen API
- KVK Zoeken API
- Schema.org Person / Organization
- GEMMA Gemeentelijk Gegevenslandschap

## Dependencies
- OpenRegister (for register schemas and object storage)
- Procest (shared BRP/KVK schemas)

## Demand
620+ tender sources mention BRP/KVK integration in CRM context.

## Risks
- BRP data is privacy-sensitive (BSN); test data must use fictitious BSN numbers
- Schema alignment with Procest requires coordination to avoid divergence
