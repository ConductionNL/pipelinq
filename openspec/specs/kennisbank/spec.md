---
status: deprecated
superseded_by: xwiki-integration
---

# Kennisbank Specification

> **Deprecated.** The bespoke in-app kennisbank was removed by the
> `migrate-kennisbank-to-xwiki-leaf` change and is superseded by the
> `xwiki-integration` proxy + components. New knowledge content lives
> in xWiki and is surfaced through the OpenRegister `integration-xwiki`
> leaf (when configured) or the Pipelinq xWiki proxy (fallback).

## Purpose

KCC agents find the answer to a citizen's question in xWiki, without leaving pipelinq. pipelinq searches xWiki through the OpenRegister leaf, shows the hits and links a page to a client, lead or ticket. Articles, categories, versions and feedback live in xWiki, so pipelinq has no article pages of its own and no `PqKennisbank` board.

**Standards**: KCS (Knowledge-Centered Service) methodology
**Feature tier**: V1

## Data Model

pipelinq stores no knowledge content. A linked page is the leaf's own reference on the object. The retired `kennisartikel`, `kenniscategorie` and `kennisfeedback` schemas stay out of the register.

## Capability Provided Via the XWiki Leaf

> UPDATED 2026-06-01: The bespoke in-app kennisbank (the `src/views/kennisbank/`
> and `src/components/kennisbank/` Vue components — `ArticleDetail.vue`,
> category tree/manager, feedback widgets — and the `kennisbank.js` store) has
> been **removed** from this app. Knowledge management is no longer a Pipelinq
> core competency: the knowledge-base capability is now provided through the
> **XWiki leaf integration** that OpenRegister exposes (`integration-xwiki`),
> surfaced on CRM objects via the leaf's tab + widget + reference-property chip.
> See the change `migrate-kennisbank-to-xwiki-leaf` (and the superseded
> `xwiki-integration` proposal). The earlier reverse-engineered requirements
> that named the deleted bespoke Vue components/methods (`fetchArticle`,
> `renderedBody`, `submitRating`, `submitSuggestion`, …) have been removed from
> this spec because the code they documented no longer exists.
>
> The requirements below describe what a user sees of the leaf. No bespoke
> kennisbank UI or store is to be re-introduced in this app (hydra ADR-022:
> consume the OR abstraction). The boards draw this: `pipelinq/PqOperationeel`,
> `pipelinq/PqWerkplekKlant` and the Kennispagina card on `pipelinq/PqKlant`.
## Requirements

---

### Requirement: Agents search xWiki from the knowledge widget

The knowledge widget SHALL search xWiki through the leaf and SHALL list each hit with its title and when it was last updated. The operational dashboard's widget SHALL name xWiki as the source. During a call the KCC workplace SHALL suggest pages based on the conversation summary.

#### Scenario: A team lead searches the knowledge base on the operational dashboard

`@e2e exclude` needs an xWiki server, which `tests/e2e/ci-seed.sh` does not provision; the unreachable-xWiki case is covered by tests/e2e/spec-coverage/kennisbank.spec.ts.

- **GIVEN** the xwiki leaf is registered
- **WHEN** a team lead searches in "Zoek in de kennisbank" on the operational dashboard
- **THEN** each hit SHALL show its title with its source and date, as in "Afvalpas kwijt of kapot" and "xWiki · bijgewerkt 2 okt"

#### Scenario: An agent on a call gets suggested pages

`@e2e exclude` needs an xWiki server and a live call, neither of which the CI instance provisions.

- **GIVEN** an agent on the KCC workplace with a client on the line
- **WHEN** the conversation summary is filled in
- **THEN** the "Kennisbank" card SHALL read "Voorgesteld op basis van de samenvatting" and list the suggested pages, each with "Open" and its last update

### Requirement: pipelinq has no article pages

pipelinq SHALL NOT offer an article list, an article editor, a category tree, article feedback, article analytics or a `/apps/pipelinq/kennisbank` route. Writing, categorising, rating and reporting on pages SHALL happen in xWiki.

#### Scenario: No knowledge base entry in the menu

`@e2e exclude` a code-absence assertion, verified by inspecting `src/manifest.json` and `appinfo/routes.php`.

- **GIVEN** the pipelinq menu
- **WHEN** a user looks for the knowledge base
- **THEN** there SHALL be no menu entry and no route for it
- **AND** the knowledge widget SHALL be the only way in from pipelinq

### Requirement: Knowledge is provided by the xwiki leaf, not an in-app wiki

Pipelinq SHALL NOT ship an in-app wiki; knowledge content, authoring, and
versioning SHALL be provided by xWiki via the OpenRegister xwiki leaf
(`integration-xwiki`), routed externally through OpenConnector (hydra ADR-022).

#### Scenario: Bespoke kennisbank and schemas are removed

`@e2e exclude` structural code/schema-absence assertion, verified by inspection rather than by a browser — a deleted view has no page to load. Confirmed: `src/views/kennisbank/`, `src/components/kennisbank/` and `src/store/modules/kennisbank.js` do not exist; no `Kennisbank*` class (service, controller or background job) exists under `lib/`; `appinfo/routes.php` registers no `kennisbank#*` / `publicKennisbank#*` route; and `lib/Settings/pipelinq_register.json` defines none of `kennisartikel`, `kenniscategorie`, `kennisfeedback` among its 27 schemas. MISMATCH REPORTED, NOT FIXED — three legacy register fragments (`lib/Settings/register.d/40-pos-cash-management.json`, `50-pos-end-of-day-bookkeeping.json`, `60-pos-split-tender.json`) still NAME those three retired slugs in `components.registers.pipelinq.schemas[]`, and that list is union-merged, so the register membership list still carries three names that resolve to no schema definition.

- **GIVEN** the migrate-kennisbank-to-xwiki-leaf change is applied
- **THEN** `src/views/kennisbank/`, `src/components/kennisbank/`,
  `src/store/modules/kennisbank.js`, the Markdown editor, and the kennisbank
  routes/controllers SHALL be removed
- **AND** the `kennisartikel`, `kenniscategorie`, and `kennisfeedback` schemas
  SHALL be retired
- **AND** page authoring SHALL live in xWiki.

#### Scenario: The bespoke xwiki-integration change is superseded

`@e2e exclude` a "SHALL NOT be built" code-absence assertion — there is no rendered surface that reveals whether a class was built, so this is a static inspection, not an e2e observation. MISMATCH REPORTED, NOT FIXED — the assertion does NOT currently hold: `lib/Controller/XWikiController.php` (routed as `xWiki#search|pages|page|status` in `appinfo/routes.php`), `lib/Service/XWikiService.php`, `src/components/xwiki/XWikiWidget.vue` and `src/components/xwiki/XWikiSidebarTab.vue` all still exist and are registered in `src/registry.js`, and `src/manifest.json` still exposes app-local `xwiki_direct_url` settings. The later `pipelinq-xwiki-through-or` change made `XWikiService::search()` prefer OpenRegister's OpenConnector-routed `/apps/openregister/api/integrations/xwiki/search` and fall back to the app-local proxy, so the hand-rolled path is retained deliberately as the fallback rather than deleted. The retained proxy's own behaviour is asserted by tests/Unit/Controller/XWikiControllerTest.php and tests/Unit/Service/XWikiServiceTest.php.

- **GIVEN** the older `xwiki-integration` change (hand-rolled proxy + widget +
  sidebar + app-local settings)
- **WHEN** this migration is applied
- **THEN** the hand-rolled `XWikiController` proxy, `XWikiWidget`,
  `XWikiSidebarTab`, and app-local xWiki settings SHALL NOT be built
- **AND** the leaf SHALL own the proxy (via OpenConnector), tab, widget, and
  settings.

### Requirement: CRM objects expose the xwiki leaf

The `client`, `lead`, and `request` schemas SHALL declare `xwiki` in
`linkedTypes` so the leaf's tab and widget appear on those objects.

#### Scenario: xWiki tab and widget appear on CRM objects

`@e2e exclude` the scenario's own GIVEN is an external system the CI instance does not provision: `tests/e2e/ci-seed.sh` installs pipelinq + openregister only, so `openconnector` is absent, no `xwiki` source is configured, and no xWiki server is reachable from the runner — the leaf cannot register, and linking a page by URL then rendering its breadcrumb / last-modified / text preview requires live xWiki content that cannot exist. MISMATCH REPORTED, NOT FIXED — independently of provisioning, the leaf tab is not placed either: the `ClientDetail` and `LeadDetail` `config.sidebar.tabs[]` in `src/manifest.json` declare only the `audit` ("History") tab, and `TicketDetail` (which absorbed `request`) declares no sidebar at all. The graceful-degradation half of this surface — what the app actually shows when xWiki is unreachable — IS covered end-to-end by tests/e2e/spec-coverage/kennisbank.spec.ts.

- **GIVEN** `openconnector` is installed with an `xwiki` source configured and
  the xwiki leaf is registered
- **WHEN** a user opens a `client`, `lead`, or `request` detail page
- **THEN** the leaf's tab SHALL allow linking an xWiki page by URL or wiki path
  and display it with breadcrumb + last-modified
- **AND** the leaf's widget SHALL show a text preview of the linked page.

### Requirement: xwiki leaf is placed via the app manifest

The xwiki leaf's tab and widget SHALL be surfaced through `src/manifest.json`
(ADR-024), and `openconnector` SHALL be declared as a dependency.

#### Scenario: Manifest places tab/widget and declares dependency

`@e2e exclude` a static manifest-content assertion ("GIVEN Pipelinq's `src/manifest.json` THEN it SHALL include …") — a toolchain check on a JSON file, machine-validated in CI by `npm run check:manifest` (`scripts/check-manifest.js`), not an observation of rendered output. Verifiable by parsing: `dependencies[]` DOES include `openconnector` (optional), and the `OperationalDashboard` page declares the widget `{"id": "xwiki-knowledge", "type": "integration", "integrationId": "xwiki", "title": "Knowledge base"}` plus its layout slot. MISMATCH REPORTED, NOT FIXED — the sidebar half is absent: no `client` / `lead` / `request` detail page declares the xwiki leaf tab in `config.sidebar.tabs[]` (ClientDetail and LeadDetail declare only `audit`; TicketDetail declares no sidebar).

- **GIVEN** Pipelinq's `src/manifest.json`
- **THEN** the client/lead/request detail pages' `sidebar` config SHALL include
  the xwiki leaf tab
- **AND** detail pages (and optionally the dashboard) MAY include the xwiki
  widget
- **AND** `dependencies[]` SHALL include `openconnector`.

### Requirement: A collectives fallback is preserved at the leaf level

The `integration-collectives` leaf SHALL be usable as a drop-in alternative for
a tenant that has no xWiki and wants NC-native-only knowledge, without app code
changes.

#### Scenario: Tenant without xWiki uses collectives

`@e2e exclude` a deployment-substitution claim about a THIRD app the CI instance does not install — the `integration-collectives` leaf lives in OpenRegister and needs the Nextcloud `collectives` app, and `tests/e2e/ci-seed.sh` provisions pipelinq + openregister only, so neither leaf can be registered and there is nothing to swap between. The pipelinq-side half of the claim is a code ABSENCE ("no pipelinq-side wiki code SHALL be required"), which no browser reveals; substituting one leaf for another is covered by OpenRegister's own leaf suite.

- **GIVEN** a tenant with no xWiki instance
- **WHEN** they prefer NC-native knowledge
- **THEN** the collectives leaf MAY be substituted (same tab/widget/reference
  contract, different backend)
- **AND** no pipelinq-side wiki code SHALL be required to support either choice.

### Requirement: Existing content migration is a documented follow-up

Migration of existing `kennisartikel` content into xWiki SHALL NOT be performed
by this change and SHALL be documented as a separate follow-up (ADR-032 bounded
scope).

#### Scenario: Follow-up is recorded, not silently dropped

`@e2e exclude` a process/documentation artefact, not runtime behaviour — "a follow-up tracking item SHALL be recorded" is satisfied by a written record, and the record exists: `openspec/changes/archive/2026-06-14-migrate-kennisbank-to-xwiki-leaf/tasks.md` §4 "Follow-up flag" (task 4.1 "Record the existing-content migration as a separate follow-up", spec_ref pointing at this requirement) and `proposal.md`. The other half — legacy `kennisartikel` / `kenniscategorie` / `kennisfeedback` objects "left in place" — is a NON-action on rows of three retired schemas that no pipelinq screen has rendered since the migration, so a browser has no view in which their continued existence could show.

- **GIVEN** existing `kennisartikel` / `kenniscategorie` / `kennisfeedback`
  objects
- **WHEN** this migration is applied
- **THEN** those objects SHALL be left in place and a follow-up tracking item
  SHALL be recorded for a one-time export → import-as-xWiki-pages → relink pass.

## Appendix

### Current Implementation Status

The in-app knowledge base is removed. `XWikiService` prefers OpenRegister's OpenConnector route and falls back to the app-local proxy. The leaf's tab is not placed on the client, lead and ticket detail pages yet, see "xwiki leaf is placed via the app manifest".

### Competitor Comparison

- **EspoCRM**: No built-in knowledge base. Relies on third-party integrations or custom entities.
- **Twenty**: No knowledge base. Rich text notes on records but no article management system.
- **Krayin**: No knowledge base. Basic notes on leads/contacts only.
- **KISS (VNG reference)**: Has a basic FAQ/kennisbank integration but not a full article management system with versioning, feedback, and zaaktype linking.
- **Pipelinq advantage**: OpenRegister's schema-based storage enables flexible article management with versioning via audit trail. Nextcloud's notification system (`NotificationService`) enables lifecycle notifications. Integration with KCC werkplek and contactmoment registration enables contextual article suggestions during calls.

### Standards & References
- Schema.org `Article`, `FAQPage`, `HowTo` -- content modeling standards
- KCS (Knowledge-Centered Service) methodology -- industry standard for knowledge management, emphasizing agent feedback loops and continuous improvement
- Nextcloud Text app -- potential integration for rich text editing (Markdown-based)
- Nextcloud Full Text Search -- potential backend for article search indexing (Enterprise feature)
- WCAG AA -- accessibility for knowledge base content and navigation
- Dutch government NORA (Nederlandse Overheid Referentie Architectuur) -- knowledge management principles for government organizations

### Specificity Assessment
- The spec is well-structured with clear CRUD scenarios, search requirements, taxonomy design, and feedback loops.
- **Implementable as-is** for the core functionality (articles, search, categories), but requires several additions to the data model.
- **Resolved design decisions:**
  - Rich text format: **Markdown** stored in OpenRegister text fields, rendered client-side with a library like `marked`.
  - Article versioning: Uses **OpenRegister's built-in audit trail** for version history (no separate version objects needed).
  - Full-text search: Uses **OpenRegister's `_search` parameter** for MVP; Nextcloud Full Text Search with Elasticsearch/Solr for Enterprise-scale deployments.
  - Feedback/rating: Stored as **separate `kennisfeedback` objects** in OpenRegister (not ICommentsManager) to enable aggregation and analytics.
  - Public articles: Served via a **public API endpoint** (no authentication required) that filters by visibility="openbaar".
- **Open questions:**
  - Should the kennisbank be a module within Pipelinq (recommended) or a separate Nextcloud app? Recommendation: module within Pipelinq, as it shares the register and is tightly coupled to KCC workflows.
  - How does the 500ms search performance requirement scale beyond 500 articles? Recommendation: OpenRegister search is sufficient for <1000 articles; Full Text Search app for larger deployments.
  - Should article content support embedded videos (e.g., instructional videos)? Recommendation: support YouTube/Vimeo embeds in Markdown via iframe syntax.

