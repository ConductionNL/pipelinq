# Proposal: kennisbank

## Problem

Pipelinq has no knowledge base functionality. KCC agents handling citizen phone calls cannot search for articles, procedures, or FAQs to answer questions quickly. This hurts first-call resolution rates (74%+ FCR targets appear in 51/52 tenders). There is no article management, no categorization, no search, and no feedback system.

## Solution

Implement a knowledge base module within Pipelinq that enables:
1. **Article CRUD** with rich text (Markdown), versioning via OpenRegister audit trail, and publish/archive lifecycle
2. **Hierarchical categories** stored as OpenRegister objects for browsable navigation
3. **Full-text search** via OpenRegister `_search` parameter with autocomplete
4. **Agent feedback** (thumbs up/down + improvement suggestions) stored as separate objects
5. **Public vs internal** article visibility with a public API endpoint
6. **Navigation integration** as a dedicated section in Pipelinq sidebar

### Approach

- Add `kennisartikel`, `kenniscategorie`, and `kennisfeedback` schemas to the pipelinq register
- Create Vue views: `KennisbankHome`, `ArticleList`, `ArticleDetail`, `ArticleEditor`
- Create backend: `KennisbankController` for public article API, feedback endpoints
- Integrate with existing `NotificationService` for lifecycle notifications
- Use Markdown rendering via `marked` library for article display

## Scope

- Article CRUD with Markdown body, status lifecycle (concept/gepubliceerd/gearchiveerd)
- Category management (hierarchical, up to 3 levels)
- Full-text search with autocomplete and snippet highlighting
- Agent feedback (thumbs up/down, improvement suggestions)
- Public vs internal visibility
- Kennisbank navigation route and sidebar entry
- Article detail view with feedback buttons

## Out of scope

- AI-assisted search (Enterprise)
- Article analytics dashboard (Enterprise)
- Multi-language article versions (V2)
- Review workflow with scheduled reminders (V2)
- Zaaktype linking UI in Procest (cross-app, separate PR)
