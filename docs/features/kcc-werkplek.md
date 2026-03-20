# KCC Werkplek (Klant Contact Centrum)

The KCC werkplek is the unified frontoffice agent screen for KCC employees, combining citizen/business identification, case visibility, contact moment registration, and backoffice routing.

## Status

**Planned** -- This feature is not yet implemented as a navigable route. The `/apps/pipelinq/kcc` URL currently redirects to the dashboard. The KCC werkplek is the most demanded capability in Dutch government CRM tenders (100% of 52 klantinteractie-tenders require it).

## Specs

- `openspec/specs/kcc-werkplek/spec.md`
- `openspec/specs/contactmomenten-rapportage/spec.md`
- `openspec/specs/omnichannel-registratie/spec.md`
- `openspec/specs/klantbeeld-360/spec.md`
- `openspec/specs/terugbel-taakbeheer/spec.md`
- `openspec/specs/kennisbank/spec.md`

## Planned Features

### Unified Agent Screen (MVP)

Single-screen workspace combining:
- **Client identification**: Search by BSN, KVK number, name, or phone
- **Client summary**: Quick view of client details and recent interactions
- **Open cases**: List of active zaken (cases) for the identified client
- **Contact moment registration**: Log the current interaction with channel, subject, and notes
- **Backoffice routing**: Assign follow-up tasks to backoffice teams

### Data Sources

The KCC werkplek orchestrates data from multiple sources:
- **Klant** (client): OpenRegister object in the `pipelinq` register
- **Contactmoment**: OpenRegister object for interaction logging
- **Zaak** (case): Retrieved via ZGW Zaken API or Procest
- **BRP/KVK enrichment**: Person/business lookup via OpenConnector sources

### Contactmomenten Rapportage (MVP)

Management dashboards and KPI monitoring for contact moments:
- Service level monitoring
- Bottleneck identification
- Staffing optimization
- Required by 98% of klantinteractie-tenders (51/52)

### Omnichannel Registratie (MVP)

Register contact moments from any channel using a unified data model:
- Phone, email, counter (MVP)
- Chat, social media, mail (V1)
- CTI integration (Enterprise)
- Required by 54% of klantinteractie-tenders (28/52)

### Klantbeeld 360 (MVP)

Comprehensive view of all interactions, cases, documents, and notes for a single person or business:
- Aggregated across all channels and systems
- Essential for consistent, informed service delivery
- Required by 83% of klantinteractie-tenders (43/52)

### Terugbel- en Taakbeheer (V1)

Callback and task management for KCC agents:
- Create callback requests (terugbelverzoeken)
- Assign follow-up tasks to backoffice colleagues
- Track through completion with priority and deadline
- Required by 31% of klantinteractie-tenders (16/52)

### Kennisbank (V1)

Searchable knowledge base for KCC agents:
- Articles, FAQs, and procedures
- Categorized and linked to zaaktypen
- KCS (Knowledge-Centered Service) methodology
- Enables first-call resolution
