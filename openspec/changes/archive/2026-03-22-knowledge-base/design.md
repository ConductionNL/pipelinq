## Context

Pipelinq thin-client CRM on OpenRegister. Kennisbank adds article management for KCC agents.

## Goals / Non-Goals

**Goals:** Searchable knowledge repository, article lifecycle, KCS feedback, public API
**Non-Goals:** AI search, analytics dashboard, standalone app, Full Text Search

## Decisions

- D1: Three schemas in same pipelinq register
- D2: Markdown content with markdown-it rendering
- D3: Frontend-only CRUD via OpenRegister API (thin client)
- D4: PublicKennisbankController for unauthenticated article access
- D5: Versioning via OpenRegister audit trail + version counter
- D6: Pinia kennisbankStore following existing patterns
- D7: Nextcloud NotificationService for lifecycle events
- D8: Split-pane Markdown editor (textarea + preview)

## Risks / Trade-offs

- Search scales via PostgreSQL FTS; Enterprise path is Full Text Search app
- Split-pane editor simpler but less friendly than WYSIWYG (deferred to V2)
