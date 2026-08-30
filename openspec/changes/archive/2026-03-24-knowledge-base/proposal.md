# Proposal: knowledge-base

## Summary

Kennisbank (knowledge base) for customer service agents within Pipelinq, providing searchable FAQ articles, standard answers, and categorized reference information. Agents can quickly find answers during customer interactions without leaving the application.

Based on market intelligence: **56 tenders, 106 requirements** in the "Knowledge base" cluster.

## Demand Evidence

### Cluster: Knowledge base (56 tenders, 106 reqs)

1. **"De Oplossing kent een kennisbank waarin standaard antwoorden op en relevante informatie voor een groot aantal vragen kunnen worden opgeslagen door beheerders. Deze kennisbank kan worden doorzocht op basis van trefwoorden."**
   - Tender: Gemeente Molenlanden - Zaaksysteem 2026 (Gemeente Molenlanden)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/392487

2. **"Als gebruiker, wil ik dat automatisch gesuggereerde kennisbank artikelen worden getoond op basis van product en/of categorie, zodat ik niet zelf hiernaar hoef te zoeken."**
   - Tender: Customer Service Platform CIBG (Ministerie van Volksgezondheid, Welzijn en Sport)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/414529

3. **"Er is een (online) kennisbank van de Opdrachtnemer. Deze kennisbank zal vrij door medewerkers van de gemeente te raadplegen zijn. De Opdrachtgever doelt hiermee op toegang tot o.a. handleidingen, FAQ's."**
   - Tender: Vergunning-, Toezicht- en Handhaving software Omgevingswet (Gemeente Zeist)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/362898

4. **"Online kennisbank met FAQ, documentatie, release notes, tips & trucs."**
   - Tender: Leveren en implementeren van een zaak- en archiefsysteem (Waterschap Noorderzijlvest)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/296963

5. **"Wij willen de mogelijkheid openhouden om in de toekomst de inhoud van de kennisbank in ieder geval deels ook te kunnen ontsluiten via onze website."**
   - Tender: Klant Contact Systeem (Veiligheidsregio Utrecht)
   - URL: https://www.tenderned.nl/aankondigingen/overzicht/371293

## Scope

### In scope
- Knowledge article entity with: title, content (rich text), category, tags/keywords, status (draft/published/archived)
- Article list view with search (full-text on title, content, keywords), filter by category, sort
- Article detail view with formatted content
- Article create/edit form for beheerders (admins/editors)
- Category management (hierarchical categories)
- Standard answers: pre-defined answer templates that agents can copy/reference during interactions
- Search within kennisbank during contact moment registration (contextual suggestions)
- Article versioning (track edits, show last-modified date and author)

### Out of scope
- Public-facing kennisbank on external website (future consideration per Veiligheidsregio Utrecht tender)
- AI-powered automatic article suggestions based on conversation context
- Chatbot integration using kennisbank content
- Multi-language article management
- Approval workflow for article publication

## Acceptance Criteria

1. **GIVEN** a beheerder wants to add knowledge, **WHEN** they create an article, **THEN** they can set title, rich-text content, category, keywords, and publish status.
2. **GIVEN** an agent is handling a customer question, **WHEN** they search the kennisbank by keyword, **THEN** matching articles are returned ranked by relevance and can be viewed inline.
3. **GIVEN** articles exist in the kennisbank, **WHEN** an agent registers a contact moment for a specific product/category, **THEN** relevant kennisbank articles are automatically suggested.
4. **GIVEN** a kennisbank with many articles, **WHEN** browsing by category, **THEN** articles are organized in a hierarchical category tree with article counts.
5. **GIVEN** an article is updated, **WHEN** viewing the article, **THEN** the last-modified date and editing author are shown, and previous versions are accessible.

## Dependencies

- **OpenRegister** -- Article and category schema definitions
- **contactmomenten** (this batch) -- Contextual suggestions during contact moment registration
- **Nextcloud Text/Editor** -- Rich text editing capabilities (or embedded markdown editor)
