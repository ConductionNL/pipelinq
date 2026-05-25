# Proposal: kennisbank

## Problem

Pipelinq's knowledge base module (kennisartikel, kenniscategorie, kennisfeedback) stores articles and categories but exposes no external REST API. External systems — citizen portals, CTI desktop clients, chatbots, and partner applications — have no way to search or retrieve knowledge base content programmatically. 84 tender evaluations explicitly require a searchable public API, giving this the highest market demand score (376) across all kennisbank features. Without it, Pipelinq cannot meet procurement requirements and integrators are forced to build brittle screen-scraping workarounds.

Additionally, admins lack API-level tools for bulk export, version comparison, and compliance auditing, which is required for document management certification and data governance.

## Solution

Extend the existing KennisbankController and KennisbankService with a full REST API layer:

1. **Public search endpoint** — full-text search over published/public articles with facets and snippet highlighting, consumable by any external system without authentication
2. **Public collections endpoint** — browse categories with article counts; per-category article listing with pagination
3. **Export endpoint** — bulk download of articles in JSON or CSV format (authenticated admin)
4. **Version comparison endpoint** — retrieve article version history and diff two versions using OpenRegister's built-in audit trail
5. **Data audit endpoint** — expose the full audit log for knowledge base entities (authenticated admin) for compliance reporting

All public endpoints are `#[PublicPage] #[NoCSRFRequired]` with CORS OPTIONS routes. All mutation or sensitive endpoints require authentication and, where appropriate, admin authorization.

### Approach

- No new OpenRegister schemas — reuses existing `kennisartikel`, `kenniscategorie`, `kennisfeedback` schemas from the 2026-03-20-kennisbank change
- Extend `KennisbankController.php` with new route handlers
- Extend `KennisbankService.php` with search, collection, export, versions, and audit methods
- Delegate to OpenRegister `IndexService` (search), `ExportService` (export), and `AuditTrailService` (versions/audit)
- Register all new routes in `appinfo/routes.php` with specific routes before wildcard `{id}` routes (per ADR-003)

## Scope

- `GET /api/kennisbank/public/search` — public full-text article search
- `GET /api/kennisbank/public/collections` — public category tree with article counts
- `GET /api/kennisbank/public/collections/{slug}/articles` — articles per category (paginated)
- `GET /api/kennisbank/articles/export` — bulk article export (JSON/CSV, admin)
- `GET /api/kennisbank/articles/{id}/versions` — version history list (authenticated)
- `GET /api/kennisbank/articles/{id}/versions/{from}/{to}` — diff between two versions (authenticated)
- `GET /api/kennisbank/audit` — data audit log (admin)
- PHPUnit tests for all new service methods
- Newman API test collection for all new endpoints (happy path + error paths)

## Out of scope

- GraphQL API for knowledge base (separate change)
- Webhook subscriptions for article publish/archive events (V2)
- Per-API-key rate limiting (V2)
- AI-assisted semantic search via embeddings (Enterprise)
- Public feedback submission via API (currently agents-only; V2)
- OpenAPI/Swagger documentation page (V2)
