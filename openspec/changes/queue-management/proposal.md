# Proposal: queue-management

## Summary

Wachtrij (queue) management and contact/case routing for Pipelinq, enabling organizations to manage incoming contacts across channels with priority queues, skill-based routing to agents/teams, werkvoorraad (work queue) management, and real-time monitoring via wallboards.

Based on market intelligence: **3 clusters totaling 177 unique tenders and 1,205 requirements** across "Queue management" (14 tenders, 162 reqs), "Call/case routing" (12 tenders, 28 reqs), and "Werkvoorraad (work queue)" (151 tenders, 1,015 reqs).

## Demand Evidence

### Cluster: Queue management (14 tenders, 162 reqs)

1. **"Het is mogelijk om een maximale Wachtrij of Wachttijd in te stellen, en de Oproep te herrouteren als deze drempels worden overschreden."**
   - Tender: Telefonie en Communicatiediensten (Sociale Verzekeringsbank)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/265055

2. **"Wallboard informatie bevat minimaal voor call, e-mail en chat: Aantal wachtenden in de wachtrij, Langst wachtende, Aantal beschikbare agenten per wachtrij, Aantal aangeboden, Aantal abandoned, Service Level."**
   - Tender: Vaste- en mobiele telefonie en Klant Contactcentrum (Stichting Slachtofferhulp Nederland)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/273552

3. **"De ContactCenter oplossing dient tenminste de volgende functionaliteit te ondersteunen: Prioriteitstelling; de prioriteit van wachtrijen kan per type wachtrij maar ook per individueel contact worden ingesteld."**
   - Tender: Contact Center as a Service (CCaaS) applicatie (Stichting het Juridisch Loket)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/307676

4. **"Een agent kan tekstberichten selectief uit de wachtrij halen (prioritair behandelen: niet per definitie het bovenste bericht in de wachtrij hoeven te selecteren)."**
   - Tender: Telecommunicatie als een Dienst (Gemeente Goes e.a.)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/296767

### Cluster: Call/case routing (12 tenders, 28 reqs)

5. **"De contactcenter-oplossing voorziet in skill based routing, waarbij: 1. Oproepen op basis van vaardigheden worden verdeeld onder contactcenter-medewerkers; 2. De vaardigheden van contactcenter-medewerkers op tenminste drie niveaus in te schalen."**
   - Tender: Telefonie en contactcenter GGD Groningen (GGD Groningen)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/401717

6. **"Beheerders of geautoriseerde personen kunnen bij calamiteiten snel en simpel diverse callflows omschakelen of inschakelen."**
   - Tender: Telefonie en Communicatiediensten (Sociale Verzekeringsbank)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/265055

### Cluster: Werkvoorraad / work queue (151 tenders, 1,015 reqs)

7. **"In de werkvoorraad en het (detail)venster wordt door middel van signaleringen getoond wanneer streef- en fatale termijnen verlopen."**
   - Tender: Gemeente Molenlanden - Zaaksysteem 2026 (Gemeente Molenlanden)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/397282

8. **"Iedere gebruiker heeft een werkvoorraad. Verzamelwerkvoorraden voor bijvoorbeeld de medewerkers van een team, rol etc. zijn in te richten."**
   - Tender: Zaak/DMS-RMA/Integratieplatform (Gemeente Stein)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/226100

9. **"De oplossing biedt de mogelijkheid om eenvoudig zaken te verdelen naar een behandelaar of naar andere behandelaars te verdelen, het aantal handelingen is beperkt."**
   - Tender: Aanschaf VTH-software (Gemeente Westerkwartier)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/264852

10. **"Historische rapportage over Wachttijden, Responsetijden, Gesprekstijden, Nawerktijden en Afhandelingstijden kunnen weergegeven worden voor alle Communicatiekanalen."**
    - Tender: Telefonie en Communicatiediensten (Sociale Verzekeringsbank)
    - URL: https://www.tenderned.nl/aankondigingen/overzicht/265055

## Scope

### In scope
- **Queue configuration**: named queues per channel/team with configurable max wait time and overflow routing
- **Priority management**: priority levels per queue and per individual contact/case
- **Werkvoorraad (personal work queue)**: per-agent inbox of assigned items with deadline signaling
- **Team werkvoorraad**: shared work queue per team/role with pick-from-queue capability
- **Assignment/routing**: manual assignment, round-robin, and skill-based routing to agents
- **Skill profiles**: agent skill tags with proficiency levels (min. 3 levels per tender requirement)
- **Wallboard/monitor view**: real-time dashboard showing queue depths, wait times, agent availability, service level
- **Deadline signaling**: visual indicators for approaching and exceeded SLA deadlines in werkvoorraad
- **Queue overflow**: automatic re-routing when queue thresholds are exceeded
- **Selective pickup**: agents can pick specific items from queue (not just FIFO)

### Out of scope
- Direct telephony/PBX integration (ACD, IVR, CTI)
- Real-time voice queue management (depends on telephony platform)
- Workforce management / shift planning
- Predictive routing using AI/ML
- Historical reporting (future: separate analytics change)

## Acceptance Criteria

1. **GIVEN** an admin configures queues, **WHEN** they create a queue with max wait time and overflow target, **THEN** items exceeding the threshold are automatically routed to the overflow queue/team.
2. **GIVEN** agents have skill profiles, **WHEN** a new contact/case enters the queue, **THEN** it is routed to an available agent whose skills match the required skill tags, respecting proficiency levels.
3. **GIVEN** an agent opens their werkvoorraad, **WHEN** items have approaching or exceeded deadlines, **THEN** visual signaling (color/icon) clearly indicates urgency and overdue items appear at the top.
4. **GIVEN** a team werkvoorraad has items, **WHEN** an agent wants to pick up work, **THEN** they can selectively choose any item from the queue (not restricted to FIFO order).
5. **GIVEN** a wallboard/monitor is configured, **WHEN** viewing it, **THEN** real-time metrics are displayed: queue depth, longest waiting, available agents per queue, service level percentage.
6. **GIVEN** a werkvoorraad for a user or team, **WHEN** the agent searches or filters, **THEN** items can be filtered by type, priority, deadline, and status, and sorted accordingly.

## Dependencies

- **client-management** (completed) -- Contacts for queue items
- **contactmomenten** (this batch) -- Contact moments flow into queues
- **callback-management** (this batch) -- Callbacks are assignable queue items
- **klachtenregistratie** (this batch) -- Complaints are assignable queue items
- **OpenRegister** -- Queue, skill profile, and assignment schemas
- **Nextcloud Groups** -- Team/role definitions for team werkvoorraad
