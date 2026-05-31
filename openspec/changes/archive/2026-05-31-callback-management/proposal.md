# Proposal: callback-management

## Summary

Terugbelverzoeken (callback request) management for Pipelinq, enabling KCC agents to schedule, assign, and track callback requests. Includes agent agenda visibility for scheduling, status tracking dashboards, and integration points for telephony systems.

Based on market intelligence: **99 tenders, 416 requirements** in the "Callback request management" cluster.

## Demand Evidence

### Cluster: Callback request management (99 tenders, 416 reqs)

1. **"De Oplossing is in staat om bij het inplannen van een terugbelverzoek de agenda van behandelaars te tonen."**
   - Tender: Gemeente Molenlanden - Zaaksysteem 2026 (Gemeente Molenlanden)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/415259

2. **"Binnen de Oplossing is het mogelijk om een overzicht te genereren met daarin de status van de klantcontacten en terugbelverzoeken. De resultaten uit dit overzicht kunnen worden gefilterd en gesorteerd."**
   - Tender: Het leveren van een zaaksysteem (Gemeente Den Helder)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/267874

3. **"Binnen de Oplossing is het mogelijk om een klantcontact en terugbelverzoek zowel opzichzelfstaand te registeren en af te handelen als gerelateerd aan een zaak en aan een afdeling, rol of gebruiker toe te wijzen."**
   - Tender: Zaaksysteem gemeente Winterswijk (Gemeente Winterswijk)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/175049

4. **"De Oplossing biedt functionaliteit om klantcontacten te kunnen registreren, zoals bijv. een klacht, een informatieverzoek of een terugbelverzoek middels verschillende kanalen."**
   - Tender: Aanbesteding VTH applicatie inclusief Omgevingswet (RUD Utrecht)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/404174

5. **"De Oplossing biedt toegang tot een klantdossier met een overzicht van openstaande en afgehandelde zaken, voorgaande klantcontactregistraties, actuele terugbelverzoeken."**
   - Tender: Leveren, implementeren en onderhouden van een Zaaksysteem (Gemeente Zaanstad)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/367903

## Scope

### In scope
- Callback request entity with: contact, phone number, preferred date/time, subject, priority, assigned agent, status
- Callback registration form (can be created standalone or from a contact moment)
- Agent agenda view showing availability for callback scheduling
- Callback list/overview with filter (status, agent, date) and sort
- Status workflow: Nieuw -> Ingepland -> Gebeld -> Afgehandeld / Niet bereikt
- "Niet bereikt" triggers re-scheduling with attempt counter
- Link callbacks to contacts, organizations, and cases
- Dashboard widget: open callbacks, overdue callbacks, callbacks per agent
- Callback assignment to agent, team, or role

### Out of scope
- Direct telephony integration (click-to-call, CTI)
- Microsoft Teams calendar sync (future integration)
- Automated callback scheduling via IVR
- SMS/WhatsApp notification to citizen about scheduled callback

## Acceptance Criteria

1. **GIVEN** a citizen requests a callback, **WHEN** the KCC agent creates a callback request, **THEN** they can specify contact, phone number, preferred date/time, subject, and assign it to a treating agent.
2. **GIVEN** a callback is being scheduled, **WHEN** the agent selects a date/time, **THEN** the agenda of available treating agents is shown to avoid scheduling conflicts.
3. **GIVEN** callbacks are registered, **WHEN** viewing the callback overview, **THEN** the status of all callbacks and contact moments can be filtered by status, agent, and date, and sorted by priority/deadline.
4. **GIVEN** a callback attempt fails (niet bereikt), **WHEN** the agent marks it as such, **THEN** the system allows re-scheduling and increments the attempt counter.
5. **GIVEN** a callback is linked to a contact, **WHEN** viewing the contact detail, **THEN** the callback request and its current status are visible in the contact's interaction history.

## Dependencies

- **client-management** (completed) -- Contacts for linking
- **contactmomenten** (this batch) -- Callbacks originate from contact moments
- **OpenRegister** -- Callback request schema definition
- **Nextcloud Calendar** (optional) -- Agent availability via CalDAV
