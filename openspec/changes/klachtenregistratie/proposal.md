<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: omnichannel-registratie (Omnichannel Registratie)
     This spec extends the existing `omnichannel-registratie` capability. Do NOT define new entities or build new CRUD — reuse what `omnichannel-registratie` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Proposal: klachtenregistratie

## Summary

Complaint registration and tracking system for Pipelinq, enabling organizations to register, categorize, and follow up on customer complaints. Complaints are linked to contacts, organizations, and cases, with full audit trail and SLA-based deadline tracking.

Based on market intelligence: **141 tenders, 637 requirements** across the "Klachtenregistratie" cluster.

## Demand Evidence

### Cluster: Klachtenregistratie
- **141 tenders** reference complaint registration functionality
- **637 requirements** extracted from public procurement documents
- Predominantly Dutch municipal and government tenders

### Representative Requirements from Tenders

1. **"Opdrachtnemer dient te beschikken over een adequaat klachtenregistratiesysteem. Bij Opdrachtnemer gemelde klachten dienen zo spoedig mogelijk, doch uiterlijk binnen 1 werkdag na melding, door Opdrachtnemer in behandeling te worden genomen."**
   - Tender: Europese openbare aanbesteding Belegde broodjes en Broodmaaltijdpakketten (Tweede Kamer der Staten-Generaal)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/415850

2. **"Het Klachtenmeldpunt stelt een Klachtendossier per aanbesteding en per Klacht samen en registreert de Klachten in een Klachtenregister."**
   - Tender: VTH-applicatie met geintegreerde zaaksysteemfunctionaliteit (Veiligheidsregio Twente)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/228225

3. **"De Opdrachtnemer dient klachten in een eigen op te zetten en te beheren digitale database te registreren en die voor de Opdrachtgever toegankelijk te maken."**
   - Tender: Gebiedsontwikkeling Groene Rivier Well (Waterschap Limburg)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/416579

4. **"Historie klachten/meldingen en oplossingen"**
   - Tender: Raamovereenkomst digitale communicatiediensten (Gemeente Middelburg)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/415626

5. **"De Oplossing biedt functionaliteit voor het registreren van controles, het registreren van klachten en meldingen, en het opvolgen hiervan."**
   - Tender: Levering en implementatie van een SaaS-oplossing ter ondersteuning van de VTH-processen (Rijkswaterstaat)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/402863

## Scope

### In scope
- Complaint entity (linked to contact/organization and optionally to a case)
- Complaint registration form with category, priority, channel, description
- Complaint list view with search, filter (status, category, priority), sort
- Complaint detail view with timeline of status changes
- Status workflow: New -> In behandeling -> Afgehandeld / Afgewezen
- SLA deadline tracking with configurable response time per category
- Link complaints to existing contacts and organizations in Pipelinq
- Complaint dashboard widget showing open/overdue counts

### Out of scope
- External complaint submission portal (future: public-intake-forms change)
- Automated complaint classification via AI/LLM
- Integration with external complaint systems (e.g., MSB, SIM)
- Escalation workflows beyond status changes (future: Procest integration)

## Acceptance Criteria

1. **GIVEN** a KCC agent receives a complaint, **WHEN** they open the complaint form, **THEN** they can register the complaint with category, priority, channel, description, and link it to a contact.
2. **GIVEN** a complaint is registered, **WHEN** the agent views the complaint list, **THEN** the complaint appears with its current status and can be filtered by status, category, and priority.
3. **GIVEN** a complaint has a configured SLA deadline, **WHEN** the deadline approaches or is exceeded, **THEN** the system visually indicates overdue complaints in the list and detail views.
4. **GIVEN** a complaint is linked to a contact, **WHEN** viewing the contact detail, **THEN** the complaint history is visible in the contact's timeline.
5. **GIVEN** a complaint status changes, **WHEN** viewing the complaint detail, **THEN** a full audit trail of status transitions with timestamps and actors is shown.

## Dependencies

- **client-management** (completed) -- Contacts and organizations must exist for linking
- **OpenRegister** -- Complaint schema to be defined in register configuration
- **contactmomenten** (this batch) -- Complaints may originate from contact moments
