<!-- ⚠️ EXTENSION NOTICE (auto-inserted by fix_extension_artifacts.py)
     Parent capability: omnichannel-registratie (Omnichannel Registratie)
     This spec extends the existing `omnichannel-registratie` capability. Do NOT define new entities or build new CRUD — reuse what `omnichannel-registratie` already provides. Your job is to add configuration, seed data, or workflow templates on top of that capability.
-->

# Proposal: contactmomenten

## Summary

Register every customer interaction (phone, email, chat, desk visit) as a structured contact moment in Pipelinq. Provides a complete contact history per person and organization, enabling KCC agents to see full interaction context before handling new contacts.

Based on market intelligence: **3 clusters totaling 401 unique tenders and 1,777 requirements** across "Contactmomenten registratie" (112 tenders, 320 reqs), "Contact history" (19 tenders, 33 reqs), and "Customer contact management" (128 tenders, 875 reqs).

## Demand Evidence

### Cluster: Contactmomenten registratie (112 tenders, 320 reqs)

1. **"Als gebruiker, wil ik kunnen zoeken en filteren binnen de contactmomenten op type kanaal en medewerker, zodat ik snel het relevante voorgaande contactmoment erbij kan zoeken."**
   - Tender: Customer Service Platform CIBG (Ministerie van Volksgezondheid, Welzijn en Sport)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/414529

2. **"De gemeente Overbetuwe zet het zaaksysteem onder andere in voor de medewerkers van het Klant Contact Centrum (KCC). Zij moeten vanuit de ICT Prestatie contactmomenten kunnen vastleggen en opvolgen, klachten registreren en terugbelverzoeken inplannen."**
   - Tender: Zaaksysteem (Gemeente Overbetuwe)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/331221

3. **"De gemeente Zaanstad vindt belangrijk dat de oplossing de medewerkers van het Klant Contact Centrum (KCC) optimaal ondersteunt. De oplossing faciliteert het vastleggen van contactmomenten."**
   - Tender: Leveren, implementeren en onderhouden van een Zaaksysteem (Gemeente Zaanstad)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/364027

### Cluster: Contact history (19 tenders, 33 reqs)

4. **"De contactcenter-agent dient meerdere contacten, aangeboden via de verschillende communicatiekanalen, tegelijk te kunnen verwerken."**
   - Tender: Telefonie en contactcenter GGD Groningen (GGD Groningen)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/401717

### Cluster: Customer contact management (128 tenders, 875 reqs)

5. **"De Oplossing kent functionaliteit waarmee een chatbot kan worden geintegreerd in het klantcontact."**
   - Tender: Gemeente Molenlanden - Zaaksysteem 2026 (Gemeente Molenlanden)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/392487

6. **"Kerncompetentie 1: Inschrijver heeft aantoonbare ervaring met het implementeren van een klantcontactsysteem bij een non-profitorganisatie."**
   - Tender: Klantcontactsysteem (Koninklijke Bibliotheek)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/289266

## Scope

### In scope
- Contact moment entity with: channel (phone, email, chat, desk, post), direction (inbound/outbound), subject, notes, duration, timestamp
- Link contact moments to contacts, organizations, and optionally to cases/complaints
- Contact moment registration form (quick-entry optimized for KCC agents)
- Contact moment list view with search, filter (channel, date range, contact, agent), sort
- Contact history panel on contact/organization detail views
- Multi-channel support: telefoon, e-mail, chat, balie, post, social media
- Agent assignment (which agent handled the interaction)
- Performance metric: results displayed within 3 seconds for 500 concurrent users (per tender requirement)

### Out of scope
- Automatic contact moment creation from telephony/email integration (future: omnichannel-registratie)
- Contact moment reporting and analytics (separate: contactmomenten-rapportage)
- Real-time channel integration (CTI, email parsing)
- Chatbot integration

## Acceptance Criteria

1. **GIVEN** a KCC agent takes a phone call, **WHEN** they open the contact moment form, **THEN** they can register the interaction with channel, direction, subject, notes, linked contact, and duration.
2. **GIVEN** a contact has prior interactions, **WHEN** an agent views the contact detail, **THEN** a chronological history of all contact moments is displayed with channel icons, timestamps, and summaries.
3. **GIVEN** a list of contact moments, **WHEN** the agent filters by channel and date range, **THEN** only matching contact moments are shown and results load within 3 seconds.
4. **GIVEN** a contact moment is linked to an organization, **WHEN** viewing the organization detail, **THEN** all contact moments for that organization (across all its contacts) are aggregated and shown.
5. **GIVEN** multiple agents handle interactions, **WHEN** viewing any contact moment, **THEN** the handling agent is recorded and visible.

## Dependencies

- **client-management** (completed) -- Contacts and organizations for linking
- **OpenRegister** -- Contact moment schema definition
- **klachtenregistratie** (this batch) -- Complaints can originate from contact moments
- **callback-management** (this batch) -- Callback requests are a type of contact moment outcome
