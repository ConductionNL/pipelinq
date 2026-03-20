# Klantbeeld 360 - Full Enrichment - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: BRP Enrichment on Demand

The system MUST allow agents to enrich person client profiles with BRP data.

#### Scenario: Enrich with BRP
- GIVEN a client with BSN linked
- WHEN the agent clicks "Verrijk met BRP"
- THEN the system MUST query the BRP via OpenConnector and display current address, nationality, partner, municipality
- AND the lookup MUST be logged in the audit trail with agent identity and doelbinding reason

### Requirement: KVK Enrichment on Demand

The system MUST allow agents to enrich organization profiles with KVK data.

#### Scenario: Enrich with KVK
- GIVEN an organization client with KVK number
- WHEN the agent clicks "Verrijk met KVK"
- THEN the system MUST display: legal form, registration date, trading names, SBI codes, vestigingen

### Requirement: Interaction History Timeline

The system MUST display a chronological timeline of all interactions for a client.

#### Scenario: Display complete interaction history
- GIVEN a client with notes, leads, requests, and contactmomenten
- WHEN the agent views the interaction history tab
- THEN all interactions MUST be displayed in reverse chronological order with type icons

### Requirement: Linked Cases (Zaken)

The system MUST display cases linked to a client from ZGW Zaken API.

#### Scenario: Display open cases
- GIVEN a client linked to 3 open zaken
- WHEN the agent views the zaken tab
- THEN all 3 cases MUST be displayed with zaaktype, status, and start date
