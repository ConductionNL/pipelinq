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

The kennisbank (knowledge base) provides KCC agents with a searchable repository of articles, FAQs, and procedures to answer citizen questions quickly and consistently. Articles are categorized, versioned, and linked to zaaktypen so agents can find the right information for each type of inquiry. This capability appears in KCS (Knowledge-Centered Service) and CRM tender requirements, and is a key enabler for first-call resolution.

**Standards**: Schema.org (`Article`, `FAQPage`, `HowTo`), KCS (Knowledge-Centered Service) methodology
**Feature tier**: V1 (core), Enterprise (AI-assisted search, analytics)
**Tender frequency**: Explicitly referenced in 1/52 KCC tenders, but implicitly required by many tenders demanding high first-call resolution rates (74%+ FCR targets appear in 51/52 tenders)

## Data Model

Knowledge base articles are stored as OpenRegister objects in the `pipelinq` register:
- **Article (kennisartikel)**: title, body (Markdown), summary, category (UUID reference), tags (array), zaaktype links (array), visibility (openbaar/intern), status (concept/gepubliceerd/gearchiveerd), version, author (Nextcloud UID), lastUpdatedBy (Nextcloud UID), publishedAt, archivedAt
- **Category (kenniscategorie)**: name, slug, parent (UUID reference for hierarchy), description, order, icon
- **Feedback (kennisfeedback)**: article (UUID reference), rating (nuttig/niet_nuttig), comment, agent (Nextcloud UID), timestamp

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
> The requirements below describe the knowledge-base *capability*. Where that
> capability is delivered, it is delivered by the XWiki leaf rather than by
> app-local controllers/components. No bespoke kennisbank UI or store is to be
> re-introduced in this app (hydra ADR-022: consume the OR abstraction).
## Requirements

---

### Requirement: Article Management

The system MUST support creating, editing, publishing, and archiving knowledge base articles with rich text content.

**Feature tier**: V1

#### Scenario: Create a new article
@e2e exclude requires article creation form (draft feature)

- GIVEN a kennisbank editor with appropriate permissions (Nextcloud group "kennisbank-editors")
- WHEN they create an article with title "Hoe vraag ik een paspoort aan?", category "Burgerzaken", body content with formatted text and links, and visibility "Openbaar"
- THEN the system MUST create an OpenRegister object with the `kennisartikel` schema in the `pipelinq` register
- AND the article MUST have status "Concept" (draft) initially
- AND the article MUST store the author's Nextcloud user UID and creation timestamp
- AND a version number of 1 MUST be assigned

#### Scenario: Publish an article
@e2e exclude requires existing draft article

- GIVEN a draft article "Hoe vraag ik een paspoort aan?"
- WHEN an editor changes the status to "Gepubliceerd"
- THEN the article MUST become visible to all KCC agents in search results
- AND the publication date MUST be recorded in the `publishedAt` property
- AND if the article is marked "Openbaar", it MUST also be available for citizen-facing channels via a public API endpoint

#### Scenario: Edit a published article (versioning)
@e2e exclude requires existing published article

- GIVEN a published article "Hoe vraag ik een paspoort aan?" at version 1
- WHEN an editor modifies the body text and saves
- THEN the system MUST increment the version number to 2
- AND the previous version MUST be retained via OpenRegister's audit trail (change history on the object)
- AND the "Laatst bijgewerkt" date MUST update to the current timestamp
- AND the `lastUpdatedBy` field MUST record the editor's Nextcloud user UID

#### Scenario: Archive an obsolete article
@e2e exclude requires existing article

- GIVEN a published article "Oud beleid afvalscheiding" that is no longer relevant
- WHEN an editor sets the status to "Gearchiveerd"
- THEN the article MUST no longer appear in default search results
- AND the article MUST still be accessible via "Toon gearchiveerd" filter toggle
- AND links to this article from other articles MUST show a "Gearchiveerd" badge with strikethrough styling

#### Scenario: Prevent duplicate article titles
@e2e exclude server validation; covered by PHPUnit

- GIVEN a published article "Hoe vraag ik een paspoort aan?" already exists
- WHEN an editor creates a new article with the same title
- THEN the system MUST display a warning "Er bestaat al een artikel met deze titel"
- AND the editor MUST be able to proceed (warning, not blocking) or navigate to the existing article

---

### Requirement: Rich Text Editing

The system MUST provide a rich text editor for article content that supports formatting, links, images, and tables.

**Feature tier**: V1

#### Scenario: Edit article with rich text
@e2e exclude requires existing article and rich text editor

- GIVEN an editor is creating or editing an article
- WHEN they use the article body editor
- THEN the editor MUST support: headings (H2-H4), bold, italic, bulleted/numbered lists, links, inline images, tables, and code blocks
- AND the content MUST be stored as Markdown for compatibility with OpenRegister text fields
- AND the editor MUST provide a live preview alongside the editing pane

#### Scenario: Insert link to another article
@e2e exclude requires multiple existing articles

- GIVEN an editor is writing article "Paspoort aanvragen"
- WHEN the editor inserts an internal link
- THEN the system MUST display a search dialog for existing articles
- AND selecting an article MUST insert a link with the article title as link text
- AND if the linked article is later archived, the link MUST show a visual warning

#### Scenario: Insert image
@e2e exclude requires file upload capability

- GIVEN an editor wants to add an instructional image to an article
- WHEN the editor clicks "Afbeelding invoegen"
- THEN the system MUST allow uploading an image or selecting from Nextcloud Files
- AND the image MUST be stored in the Nextcloud Files folder "Open Registers/Pipelinq/Kennisbank/"
- AND the image MUST be displayed inline in the article with alt text

---

### Requirement: Search and Discovery

The system MUST provide fast, full-text search across all published articles to help agents find answers during live contacts.

**Feature tier**: V1

#### Scenario: Full-text search
@e2e exclude kennisbank views (src/views/kennisbank) are not registered in the manifest navigation/pages — draft surface unreachable in the current app shell; OR full-text `_search` covered by Newman

- GIVEN 200 published articles in the kennisbank
- WHEN an agent searches for "paspoort verlengen"
- THEN the system MUST return relevant articles ranked by relevance
- AND search MUST cover title, body text, summary, and tags
- AND results MUST display: title, category, relevance indicator, and a text snippet (max 200 chars) with highlighted matches

#### Scenario: Search with zero results
@e2e exclude requires search and known-empty result state

- GIVEN an agent searches for "kwarktaart recept"
- WHEN no articles match the query
- THEN the system MUST display "Geen resultaten gevonden"
- AND the system MUST suggest: "Probeer andere zoektermen" or show the most popular categories as browsing alternatives

#### Scenario: Search during active contact
@e2e exclude requires KCC werkplek context

- GIVEN an agent is handling a phone call in the KCC werkplek
- WHEN the agent types a search query in the kennisbank search panel
- THEN results MUST appear within 500ms (while the citizen is on the phone)
- AND the agent MUST be able to view an article in a side panel without leaving the KCC werkplek context
- AND the search MUST use OpenRegister's full-text search capability with `_search` parameter

#### Scenario: Search autocomplete
@e2e exclude requires article data for autocomplete

- GIVEN an agent starts typing "pas" in the kennisbank search
- WHEN at least 3 characters have been entered
- THEN the system MUST display autocomplete suggestions from article titles matching the prefix
- AND selecting a suggestion MUST navigate directly to that article
- AND the autocomplete dropdown MUST show max 5 suggestions with category labels

#### Scenario: Recently viewed articles
@e2e exclude requires article view history

- GIVEN an agent has viewed 10 articles today
- WHEN the agent opens the kennisbank without entering a search query
- THEN the system MUST display the agent's 5 most recently viewed articles
- AND each entry MUST show: title, category, and time since last viewed
- AND this data MUST be stored client-side (localStorage) for privacy

---

### Requirement: Categorization and Taxonomy

The system MUST support hierarchical categories for organizing articles and enabling browsable navigation.

**Feature tier**: V1

#### Scenario: Browse articles by category
@e2e exclude kennisbank views are not wired into the manifest navigation/pages — draft surface unreachable in the current app shell

- GIVEN categories: "Burgerzaken" (with subcategories "Paspoort", "Rijbewijs", "Uittreksel"), "Belastingen", "Vergunningen"
- WHEN an agent browses the category "Burgerzaken > Paspoort"
- THEN the system MUST display all published articles in the "Paspoort" subcategory
- AND the breadcrumb navigation MUST show: Kennisbank > Burgerzaken > Paspoort
- AND each category MUST show the article count in parentheses

#### Scenario: Article in multiple categories
@e2e exclude requires articles with categories

- GIVEN an article "Verhuizing doorgeven" relevant to both "Burgerzaken" and "Belastingen"
- WHEN an editor assigns both categories via the tags array
- THEN the article MUST appear in both category views
- AND removing from one category MUST NOT affect the other

#### Scenario: Category management
@e2e exclude admin feature; not separately testable without data

- GIVEN an administrator manages the kennisbank taxonomy
- WHEN they create a new category "Duurzaamheid" under root with order 5
- THEN the category MUST appear in the category tree navigation
- AND the category MUST be available for article assignment
- AND categories MUST support up to 3 levels of hierarchy (root > level1 > level2)

#### Scenario: Empty category indication
@e2e exclude requires empty category

- GIVEN category "Vergunningen > Evenementen" has no published articles
- WHEN an agent browses the category tree
- THEN the empty category MUST be displayed with "(0)" count
- AND the category MUST still be browsable (not hidden)
- AND a message "Nog geen artikelen in deze categorie" MUST be shown

---

### Requirement: Zaaktype Linking

The system MUST support linking articles to specific zaaktypen, so agents handling a particular type of case can quickly find relevant knowledge.

**Feature tier**: V1

#### Scenario: Link article to zaaktype
@e2e exclude ZGW integration; V1 feature

- GIVEN an article "Procedure bouwvergunning" and zaaktype "Omgevingsvergunning bouwen"
- WHEN an editor links the article to the zaaktype via the `zaaktypeLinks` array property
- THEN the article MUST appear when an agent views a zaak of that type and clicks "Kennisbank"
- AND the link MUST be stored on the article as a zaaktype reference (UUID or identifier)

#### Scenario: View related articles from a case
@e2e exclude requires Procest integration

- GIVEN an agent is viewing zaak "Bouwvergunning #2024-001" of type "Omgevingsvergunning bouwen"
- AND 3 kennisbank articles are linked to this zaaktype
- WHEN the agent clicks the "Kennisbank" button on the case view
- THEN the system MUST display the 3 related articles ordered by usefulness rating (highest first)
- AND the articles MUST be displayed in a dropdown or side panel

#### Scenario: Suggest articles during contact registration
@e2e exclude KCC werkplek feature; draft

- GIVEN an agent is registering a contactmoment with subject category "Vergunningen"
- WHEN the agent selects the subject category
- THEN the system MUST display a "Relevante artikelen" suggestion panel with articles tagged with the "Vergunningen" category
- AND the panel MUST show max 5 articles, ordered by popularity

---

### Requirement: Agent Feedback

The system MUST allow agents to rate articles for usefulness and suggest improvements, supporting continuous knowledge improvement (KCS methodology).

**Feature tier**: V1

#### Scenario: Rate article usefulness
@e2e exclude requires existing article

- GIVEN an agent reads article "Hoe vraag ik een paspoort aan?" to answer a citizen question
- WHEN the agent clicks "Nuttig" (thumbs up) or "Niet nuttig" (thumbs down)
- THEN the system MUST create a `kennisfeedback` object in OpenRegister with: article UUID, rating, agent UID, timestamp
- AND the article's aggregate usefulness score MUST be recalculated
- AND the score MUST influence search result ranking (articles with higher scores rank higher)

#### Scenario: Suggest article improvement
@e2e exclude requires existing article

- GIVEN an agent finds that article "Tarieven rijbewijs" contains outdated pricing
- WHEN the agent clicks "Suggestie" and enters "Tarieven zijn per 2024 gewijzigd, huidige prijzen kloppen niet"
- THEN the system MUST create a feedback object with rating "niet_nuttig" and the comment text
- AND kennisbank editors MUST receive a Nextcloud notification via `NotificationService` about the suggestion
- AND the feedback item MUST track status: nieuw, in behandeling, verwerkt

#### Scenario: View article feedback summary
@e2e exclude requires feedback data

- GIVEN article "Paspoort aanvragen" has 45 "nuttig" ratings and 5 "niet nuttig" ratings over the past month
- WHEN an editor views the article management page
- THEN the system MUST display: total views (estimated), thumbs up count (45), thumbs down count (5), satisfaction rate (90%), and latest improvement suggestions
- AND articles with satisfaction rate below 70% MUST be flagged for review

#### Scenario: Feedback-driven review workflow
@e2e exclude V1 workflow; not yet implemented

- GIVEN 3 improvement suggestions have been submitted for article "Tarieven rijbewijs" in the past week
- WHEN an editor views the article
- THEN the system MUST display a "Review vereist" badge on the article
- AND the editor MUST be able to mark suggestions as "Verwerkt" after updating the article
- AND marking as verwerkt MUST remove the review badge

---

### Requirement: Public vs Internal Articles

The system MUST distinguish between articles visible only to agents (internal) and articles also available for citizen-facing channels (public).

**Feature tier**: V1

#### Scenario: Internal-only article
@e2e exclude requires articles with visibility settings

- GIVEN an article "Escalatieprotocol agressieve burgers" with visibility "Intern"
- WHEN a citizen accesses the public knowledge base API
- THEN the article MUST NOT be returned by the API
- AND the article MUST only be visible to authenticated Nextcloud users with KCC role

#### Scenario: Public article via API
@e2e exclude API endpoint; covered by Newman

- GIVEN an article "Hoe vraag ik een paspoort aan?" with visibility "Openbaar"
- WHEN a citizen-facing application queries the public kennisbank API
- THEN the article MUST be returned with: title, summary, body, category, and tags
- AND internal-only fields (author UID, feedback data, zaaktype links) MUST NOT be included in the public response

#### Scenario: Mixed visibility in agent view
@e2e exclude requires articles with different visibility

- GIVEN an agent searches the kennisbank and results include both public and internal articles
- WHEN the results are displayed
- THEN each article MUST show a visibility badge: "Openbaar" (green) or "Intern" (gray)
- AND the agent MUST be able to filter by visibility

---

### Requirement: Article Lifecycle Notifications

The system MUST notify relevant users about article lifecycle events to ensure knowledge stays current.

**Feature tier**: V1

#### Scenario: Review reminder for aging articles
@e2e exclude background job; covered by PHPUnit

- GIVEN a published article "Tarieven afvalstoffenheffing" was last updated 180 days ago
- AND the configured review interval is 180 days
- WHEN the background job checks for aging articles
- THEN the article author MUST receive a Nextcloud notification: "Artikel 'Tarieven afvalstoffenheffing' is 180 dagen niet bijgewerkt. Controleer of de inhoud nog actueel is."
- AND the article MUST show a "Review nodig" badge in the article list

#### Scenario: Notification on article archive
@e2e exclude PHP notification; covered by PHPUnit

- GIVEN article "Oud parkeerbeleid" is archived by an editor
- AND 3 other articles link to "Oud parkeerbeleid"
- WHEN the archiving is saved
- THEN the editors of the 3 linking articles MUST receive a notification that a linked article has been archived
- AND the linking articles MUST show a warning about the broken link

#### Scenario: New article notification to team
@e2e exclude PHP notification; covered by PHPUnit

- GIVEN a new article "Nieuwe regels energielabel" is published in category "Vergunningen"
- WHEN the article status changes to "Gepubliceerd"
- THEN agents subscribed to the "Vergunningen" category MUST receive a notification about the new article
- AND the notification MUST include the article title and a link

---

### Requirement: Article Analytics

The system MUST track article usage to help editors understand which articles are most valuable and which need improvement.

**Feature tier**: Enterprise

#### Scenario: Most-viewed articles report
@e2e exclude V1 analytics; not yet implemented

- GIVEN the kennisbank has been active for 3 months
- WHEN an editor views the analytics dashboard
- THEN the system MUST display the top 20 most-viewed articles with view count, unique viewers, and average time on article
- AND articles with declining views MUST be highlighted

#### Scenario: Search terms without results report
@e2e exclude V1 analytics; not yet implemented

- GIVEN agents have searched for 50 unique terms this month
- WHEN an editor views the "Ontbrekende kennis" report
- THEN the system MUST display search terms that returned zero results, ranked by frequency
- AND each term MUST show the number of times it was searched
- AND the editor MUST be able to click a term to create a new article pre-filled with the search term as title

#### Scenario: Article coverage by zaaktype
@e2e exclude ZGW integration analytics; V1 feature

- GIVEN 20 zaaktypen are configured in the system
- WHEN an editor views the coverage report
- THEN the system MUST display which zaaktypen have linked articles and which do not
- AND zaaktypen without articles MUST be flagged as "Geen kennisartikelen beschikbaar"
- AND the report MUST suggest creating articles for uncovered zaaktypen

---

### Requirement: Kennisbank Navigation

The system MUST provide a dedicated navigation section for the kennisbank within the Pipelinq app.

**Feature tier**: V1

#### Scenario: Kennisbank as navigation item
@e2e exclude no "Kennisbank" entry exists in src/manifest.json menu/pages — the navigation item is not yet wired in (draft); cannot be driven via the app shell

- GIVEN a KCC agent opens Pipelinq
- WHEN the agent clicks "Kennisbank" in the left navigation sidebar
- THEN the system MUST display the kennisbank home page with: search bar, category tree, recently updated articles, and popular articles
- AND the route MUST be `/apps/pipelinq/kennisbank`

#### Scenario: Article detail view
@e2e exclude kennisbank ArticleDetail view is not wired into the manifest navigation/pages — draft surface unreachable in the current app shell

- GIVEN the agent clicks on article "Hoe vraag ik een paspoort aan?"
- WHEN the article detail page loads
- THEN the system MUST display: title, body (rendered Markdown), category breadcrumb, tags, last updated date, author name, version number, and related articles
- AND the page MUST include the feedback buttons (Nuttig/Niet nuttig) and a "Suggestie" link
- AND the page MUST include a "Terug naar zoekresultaten" link if the agent came from a search

#### Scenario: Keyboard navigation for accessibility
@e2e exclude kennisbank views are not wired into the manifest navigation/pages — draft surface unreachable in the current app shell

- GIVEN an agent is using the kennisbank with keyboard only
- WHEN the agent navigates via Tab key
- THEN the search field MUST be the first focusable element
- AND category tree items MUST be navigable with arrow keys
- AND all interactive elements MUST have visible focus indicators (WCAG AA)

---

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

### Requirement: Knowledge base UI — documented operations

The knowledge base screens implemented in this app MUST provide the operations enumerated in this change's tasks.md (for example `fetchArticle`, `renderedBody`, `submitRating`, `submitSuggestion`). Each listed method realises an observable part of knowledge base screens and MUST behave as implemented in the current codebase.

**Feature tier**: V1

#### Scenario: Documented operations are available

- GIVEN the frontend component/store is loaded
- WHEN a caller invokes one of the documented operations for knowledge base screens
- THEN the operation MUST execute and return a result consistent with the current implementation

---

### Requirement: Knowledge base UI — results derived from current CRM state

Operations for knowledge base screens MUST read their inputs from the relevant CRM entities/configuration and compute results from that live state (no hard-coded or stubbed responses). Derivations such as formatting, aggregation, filtering and validation MUST reflect the data present at call time.

**Feature tier**: V1

#### Scenario: Results reflect live state

- GIVEN CRM data backing knowledge base screens
- WHEN a documented operation runs
- THEN its output MUST be derived from that data
- AND it MUST change when the underlying data changes

---

### Requirement: Knowledge base UI — defensive handling of absent or invalid input

Operations for knowledge base screens MUST tolerate missing, empty, or malformed input without throwing unhandled errors — returning empty or default results, or surfacing a validation outcome as implemented, rather than crashing the surrounding flow.

**Feature tier**: V1

#### Scenario: Missing input does not crash the flow

- GIVEN an operation for knowledge base screens is called with absent or invalid input
- WHEN it executes
- THEN it MUST return a safe default or a validation result
- AND it MUST NOT raise an unhandled exception

## Appendix

### Current Implementation Status

**Implemented (V1 core).** Knowledge base functionality is live as of 2026-03-24.

- Schemas `kennisartikel`, `kenniscategorie`, `kennisfeedback` defined in `lib/Settings/pipelinq_register.json`
- Backend: `KennisbankService`, `KennisbankController`, `PublicKennisbankController`, `KennisbankReviewJob`
- Frontend: `KennisbankHome`, `ArticleDetail`, `ArticleEditor`, `CategoryManager` views
- Components: `ArticleListItem`, `CategoryTree`, `CategoryTreeNode`, `ArticleFeedback`, `FeedbackSummary`
- Pinia store: `kennisbank.js` with full CRUD, search, autocomplete, feedback, recently viewed
- Rich text: Markdown editor with live preview using `markdown-it`
- Full-text search via OpenRegister `_search` parameter
- Agent feedback with thumbs up/down + improvement suggestions
- Public vs internal article visibility with public API endpoint
- Article lifecycle notifications via `KennisbankReviewJob` background job
- 31 PHPUnit tests covering service, controllers, and background job
- **Not yet implemented**: Zaaktype linking (deferred), Article Analytics (Enterprise tier)
- No kennisbank route in `src/router/index.js`.
- No `NotificationService` integration for article lifecycle events (though the service exists).

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

