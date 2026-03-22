# Proposal: customer-satisfaction

## Summary

Klanttevredenheid (customer satisfaction) measurement for Pipelinq, enabling organizations to send surveys after case closure or service interactions, collect feedback, and analyze satisfaction scores per channel, team, and period.

Based on market intelligence: **29 tenders, 82 requirements** in the "Customer satisfaction" cluster.

## Demand Evidence

### Cluster: Customer satisfaction (29 tenders, 82 reqs)

1. **"De Oplossing maakt het mogelijk om per Communicatiekanaal een klanttevredenheidsonderzoek uit te voeren middels het invullen van een vragenlijst."**
   - Tender: Telefonie en Communicatiediensten (Sociale Verzekeringsbank)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/265055

2. **"Klanttevredenheidsonderzoeken kunnen worden aangeboden in het Nederlands, Duits en Engels, waarbij de Klant zelf kan kiezen welke taal de voorkeur heeft."**
   - Tender: Telefonie en Communicatiediensten (Sociale Verzekeringsbank)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/265055

3. **"Klanten krijgen de mogelijkheid na een klanttevredenheidsonderzoek hun contactgegevens achter te laten, zodat de organisatie kan reageren op eventuele klachten."**
   - Tender: Telefonie en Communicatiediensten (Sociale Verzekeringsbank)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/265055

4. **"(C)KTO: (Continu) Klant Tevredenheid Onderzoek. Het door middel van Customer Survey meten van de klanttevredenheid over de Kanalen van de dienstverlening. KTO is een onderdeel van de Wmo-verplichting."**
   - Tender: Contact Center as a Service (CCaaS) (Belastingdienst)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/383232

5. **"De functie ondersteunt activiteiten op het gebied van verbetermanagement, waaronder functies voor het melden/ophalen, behandelen/analyseren en afhandelen/archiveren van (feedback)opmerkingen."**
   - Tender: Omnichannel informatievoorziening (Gemeente Amsterdam)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/300113

6. **"Opdrachtnemer houdt minimaal 1 keer per jaar een klanttevredenheidsonderzoek en stelt de resultaten hiervan beschikbaar."**
   - Tender: Compute, Storage en Backup (Gemeente Amersfoort)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/411198

## Scope

### In scope
- Survey template entity: configurable question sets with rating scales (1-5, 1-10, NPS), open text, and multiple choice
- Survey distribution: trigger survey after case closure or contact moment, via email link
- Survey response collection: public form (no login required) for citizens
- Results dashboard: average scores per channel, per team, per period
- Link survey responses to contacts, cases, and contact moments
- NPS (Net Promoter Score) calculation
- Multi-language survey support (NL, EN minimum; DE optional per tender requirement)
- Export survey results to CSV

### Out of scope
- Real-time post-call IVR surveys (requires telephony integration)
- SMS-based survey distribution
- Advanced statistical analysis / trend prediction
- Integration with external survey tools (e.g., SurveyMonkey)
- Automated follow-up workflows based on low scores (future: klachtenregistratie link)

## Acceptance Criteria

1. **GIVEN** an admin configures a survey template, **WHEN** they create questions with rating scales and open text fields, **THEN** the template is saved and can be linked to case types or channels.
2. **GIVEN** a case is closed, **WHEN** the survey trigger is active for that case type, **THEN** the citizen receives a survey invitation via email with a unique response link.
3. **GIVEN** a citizen opens the survey link, **WHEN** they fill in the survey, **THEN** the response is recorded and linked to the original case/contact without requiring login.
4. **GIVEN** survey responses are collected, **WHEN** a manager views the satisfaction dashboard, **THEN** average scores, NPS, and response rates are shown filterable by channel, team, and date range.
5. **GIVEN** a citizen leaves feedback with contact details, **WHEN** the feedback indicates dissatisfaction (score below threshold), **THEN** the system allows creating a follow-up complaint or callback request.

## Dependencies

- **contactmomenten** (this batch) -- Surveys linked to contact moments
- **klachtenregistratie** (this batch) -- Low satisfaction can trigger complaint creation
- **OpenRegister** -- Survey template, question, and response schemas
- **public-intake-forms** (cross-project) -- Reuse public form infrastructure for survey responses
## Why

Pipelinq tracks client interactions but cannot measure satisfaction. KTO surveys and NPS are required for government service quality and commercial CRM.

## What Changes

- New `survey` and `surveyResponse` schemas in OpenRegister
- Survey CRUD management UI, public response collection, NPS/satisfaction analytics
- Dashboard integration with satisfaction KPI card and trend widget

## Capabilities

### New Capabilities
- `customer-satisfaction`: KTO survey CRUD, public response collection, NPS calculation, analytics, entity linking, dashboard widgets

### Modified Capabilities
- `dashboard`: Add satisfaction KPI card and trend widget

## Impact

- Data model: Two new schemas in `pipelinq_register.json`
- Backend: PublicSurveyController for unauthenticated response submission
- Frontend: Survey views, store, dashboard widgets, navigation integration
- Feature tier: V1
