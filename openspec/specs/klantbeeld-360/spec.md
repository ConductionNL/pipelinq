# Klantbeeld 360 Specification

## Status: partial

## Purpose

Klantbeeld 360 provides a comprehensive, aggregated view of all interactions, cases, documents, and notes for a single person or business. 83% of klantinteractie-tenders require a 360-degree customer view.

---

## Requirements

### Requirement: Basic Client Profile Display

**Status: implemented**

The system MUST display client profile information on the detail page.

#### Scenario: View client profile
- GIVEN a client "Jan de Vries" with email, phone, website, address
- WHEN the user opens the client detail page
- THEN the system MUST display: name, type, email, phone, website, address, notes

#### Scenario: Contact persons on organization
- GIVEN an organization client with linked contact persons
- WHEN the user views the detail page
- THEN the contacts table MUST show all linked contact persons

#### Scenario: Nextcloud Contacts sync badge
- GIVEN a client with contactsUid set
- WHEN the user views the detail page
- THEN the "Synced with Contacts" badge MUST be displayed

---

## Unimplemented Requirements

The following requirements are tracked as a change proposal:

**Change:** `openspec/changes/klantbeeld-360-enrichment/`

- BRP enrichment on demand (BSN lookup via OpenConnector)
- KVK enrichment on demand (detailed company data)
- Interaction history timeline (aggregated from all sources)
- Linked cases (zaken) from ZGW Zaken API
- Document management via Nextcloud Files
- Aggregated contactmomenten display
- Tab-based layout (Profile, Timeline, Zaken, Documenten)
- BSN field with masked display
- AVG audit trail for BRP lookups

---

### Implementation References

- `src/views/clients/ClientDetail.vue` -- basic client profile display
- `lib/Service/KvkApiClient.php` -- KVK API integration (exists for prospect discovery)
