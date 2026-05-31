> SUPERSEDED 2026-05-31: feature implemented; archived twin archive/2026-03-21-kennisbank. Archived as already-delivered. (Bespoke kennisbank subsequently migrated to the OpenRegister xwiki leaf — see migrate-kennisbank-to-xwiki-leaf.)

# Proposal: kennisbank

## Summary

Add a knowledge base module to Pipelinq so KCC agents can instantly look up articles, procedures, and FAQs during citizen phone calls. First-call resolution (FCR) is the single most cited KPI in Dutch government tenders — 74%+ FCR targets appear in 51 of 52 analysed tenders — and the absence of a searchable knowledge base is the primary structural barrier to hitting that target. This change adds article authoring, hierarchical categories, full-text search, agent feedback, and a public article API for citizen self-service.

## Demand Evidence

### Cluster: Kennisbank / First-call resolution (51 tenders, 74%+ FCR target cited in each)

1. **"KCC medewerkers moeten tijdens een telefonisch contact direct kunnen zoeken in een kennisbank met procedures, FAQ's en werkinstructies."**
   - Requirement type: Functional — KCC werkplek
   - Context: Municipalities contracting an omnichannel KCC platform consistently require agents to access a built-in knowledge base without switching applications.

2. **"Het systeem ondersteunt een kennisbank waarmee medewerkers snel antwoord kunnen vinden op vragen van burgers. Eerste-lijn oplossingspercentage dient minimaal 74% te zijn."**
   - Requirement type: KPI / SLA — first-call resolution
   - Context: 74% FCR target is a contractual obligation in 51/52 tenders reviewed; agent-facing knowledge access is the primary enabler.

3. **"Kennisartikelen moeten gepubliceerd kunnen worden voor burgers via de gemeentelijke website (zelfbediening), conform SDG vereisten."**
   - Requirement type: Public self-service
   - Context: Public-facing article API enables integration with municipal websites and reduces inbound call volume — second-order FCR improvement.

4. **"Feedback van medewerkers op kennisartikelen wordt bijgehouden zodat verouderde of onjuiste content gesignaleerd en verbeterd kan worden."**
   - Requirement type: Knowledge quality management
   - Context: KCS (Knowledge-Centered Service) methodology requirement; continual improvement of article quality directly impacts FCR rates.

## Scope

### In scope
- Article CRUD with Markdown body, status lifecycle (concept / gepubliceerd / gearchiveerd)
- Hierarchical category management (up to 3 levels, slug-based navigation)
- Full-text search with autocomplete and snippet highlighting via OpenRegister `_search`
- Agent feedback: thumbs up/down (nuttig / niet nuttig) with optional improvement comment
- Aggregate usefulness score recalculated on each feedback submission
- Public vs internal article visibility (`intern` / `openbaar`)
- Public API endpoint (`GET /api/kennisbank/public`) filtered by `status=gepubliceerd` AND `visibility=openbaar`
- Kennisbank navigation route and sidebar entry in `MainMenu.vue`
- Article detail view with rendered Markdown, category breadcrumb, tags, and feedback buttons

### Out of scope
- AI-assisted search (Enterprise)
- Article analytics dashboard (Enterprise)
- Multi-language article versions (V2)
- Review workflow with scheduled reminders (V2)
- Zaaktype linking UI in Procest (cross-app, separate PR)

## Acceptance Criteria

1. **GIVEN** a KCC agent receives a citizen phone call about a permit procedure, **WHEN** they type three or more characters in the kennisbank search bar, **THEN** matching article titles and snippets appear as autocomplete suggestions within 1 second.

2. **GIVEN** a knowledge manager publishes an article with `visibility=openbaar`, **WHEN** the public API endpoint `GET /api/kennisbank/public` is called without authentication, **THEN** the article appears in results; internal fields (author UID, zaaktype links) are stripped from the response.

3. **GIVEN** a KCC agent reads an article and finds it helpful, **WHEN** they click "Nuttig", **THEN** a feedback object is created, the article's `usefulnessScore` is recalculated, and the button is marked as selected without a page reload.

4. **GIVEN** an article has status `concept`, **WHEN** a manager clicks "Publiceren", **THEN** the article status changes to `gepubliceerd`, `publishedAt` is set to the current timestamp, and the article becomes visible in the kennisbank home view.

5. **GIVEN** at least two levels of categories exist (e.g., "Burgerzaken" → "Paspoort en ID"), **WHEN** an agent browses the category tree in `KennisbankHome.vue`, **THEN** the hierarchy is displayed in a collapsible tree with article counts per category.

6. **GIVEN** a kennisartikel is saved with `visibility=intern`, **WHEN** the public API `GET /api/kennisbank/public` is called, **THEN** the article does NOT appear in results regardless of its status.

## Dependencies

- **OpenRegister** — provides register/schema/object CRUD, full-text search (`_search`), and audit trail for versioning
- **client-management** (completed) — clients and contacts linked to contact moments that may reference knowledge articles
- **queue-management** (completed) — agents already use Pipelinq; kennisbank is a new sidebar section in the same app shell
- **NotificationService** — lifecycle notifications on article publish/archive events
