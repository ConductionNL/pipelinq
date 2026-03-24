---
status: implemented
---

# Kennisbank Specification

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

## ADDED Requirements

---

### Requirement: Article Management

The system MUST support creating, editing, publishing, and archiving knowledge base articles with rich text content.

**Feature tier**: V1

#### Scenario: Create a new article

- GIVEN a kennisbank editor with appropriate permissions (Nextcloud group "kennisbank-editors")
- WHEN they create an article with title "Hoe vraag ik een paspoort aan?", category "Burgerzaken", body content with formatted text and links, and visibility "Openbaar"
- THEN the system MUST create an OpenRegister object with the `kennisartikel` schema in the `pipelinq` register
- AND the article MUST have status "Concept" (draft) initially
- AND the article MUST store the author's Nextcloud user UID and creation timestamp
- AND a version number of 1 MUST be assigned

#### Scenario: Publish an article

- GIVEN a draft article "Hoe vraag ik een paspoort aan?"
- WHEN an editor changes the status to "Gepubliceerd"
- THEN the article MUST become visible to all KCC agents in search results
- AND the publication date MUST be recorded in the `publishedAt` property
- AND if the article is marked "Openbaar", it MUST also be available for citizen-facing channels via a public API endpoint

#### Scenario: Edit a published article (versioning)

- GIVEN a published article "Hoe vraag ik een paspoort aan?" at version 1
- WHEN an editor modifies the body text and saves
- THEN the system MUST increment the version number to 2
- AND the previous version MUST be retained via OpenRegister's audit trail (change history on the object)
- AND the "Laatst bijgewerkt" date MUST update to the current timestamp
- AND the `lastUpdatedBy` field MUST record the editor's Nextcloud user UID

#### Scenario: Archive an obsolete article

- GIVEN a published article "Oud beleid afvalscheiding" that is no longer relevant
- WHEN an editor sets the status to "Gearchiveerd"
- THEN the article MUST no longer appear in default search results
- AND the article MUST still be accessible via "Toon gearchiveerd" filter toggle
- AND links to this article from other articles MUST show a "Gearchiveerd" badge with strikethrough styling

#### Scenario: Prevent duplicate article titles

- GIVEN a published article "Hoe vraag ik een paspoort aan?" already exists
- WHEN an editor creates a new article with the same title
- THEN the system MUST display a warning "Er bestaat al een artikel met deze titel"
- AND the editor MUST be able to proceed (warning, not blocking) or navigate to the existing article

---

### Requirement: Rich Text Editing

The system MUST provide a rich text editor for article content that supports formatting, links, images, and tables.

**Feature tier**: V1

#### Scenario: Edit article with rich text

- GIVEN an editor is creating or editing an article
- WHEN they use the article body editor
- THEN the editor MUST support: headings (H2-H4), bold, italic, bulleted/numbered lists, links, inline images, tables, and code blocks
- AND the content MUST be stored as Markdown for compatibility with OpenRegister text fields
- AND the editor MUST provide a live preview alongside the editing pane

#### Scenario: Insert link to another article

- GIVEN an editor is writing article "Paspoort aanvragen"
- WHEN the editor inserts an internal link
- THEN the system MUST display a search dialog for existing articles
- AND selecting an article MUST insert a link with the article title as link text
- AND if the linked article is later archived, the link MUST show a visual warning

#### Scenario: Insert image

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

- GIVEN 200 published articles in the kennisbank
- WHEN an agent searches for "paspoort verlengen"
- THEN the system MUST return relevant articles ranked by relevance
- AND search MUST cover title, body text, summary, and tags
- AND results MUST display: title, category, relevance indicator, and a text snippet (max 200 chars) with highlighted matches

#### Scenario: Search with zero results

- GIVEN an agent searches for "kwarktaart recept"
- WHEN no articles match the query
- THEN the system MUST display "Geen resultaten gevonden"
- AND the system MUST suggest: "Probeer andere zoektermen" or show the most popular categories as browsing alternatives

#### Scenario: Search during active contact

- GIVEN an agent is handling a phone call in the KCC werkplek
- WHEN the agent types a search query in the kennisbank search panel
- THEN results MUST appear within 500ms (while the citizen is on the phone)
- AND the agent MUST be able to view an article in a side panel without leaving the KCC werkplek context
- AND the search MUST use OpenRegister's full-text search capability with `_search` parameter

#### Scenario: Search autocomplete

- GIVEN an agent starts typing "pas" in the kennisbank search
- WHEN at least 3 characters have been entered
- THEN the system MUST display autocomplete suggestions from article titles matching the prefix
- AND selecting a suggestion MUST navigate directly to that article
- AND the autocomplete dropdown MUST show max 5 suggestions with category labels

#### Scenario: Recently viewed articles

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

- GIVEN categories: "Burgerzaken" (with subcategories "Paspoort", "Rijbewijs", "Uittreksel"), "Belastingen", "Vergunningen"
- WHEN an agent browses the category "Burgerzaken > Paspoort"
- THEN the system MUST display all published articles in the "Paspoort" subcategory
- AND the breadcrumb navigation MUST show: Kennisbank > Burgerzaken > Paspoort
- AND each category MUST show the article count in parentheses

#### Scenario: Article in multiple categories

- GIVEN an article "Verhuizing doorgeven" relevant to both "Burgerzaken" and "Belastingen"
- WHEN an editor assigns both categories via the tags array
- THEN the article MUST appear in both category views
- AND removing from one category MUST NOT affect the other

#### Scenario: Category management

- GIVEN an administrator manages the kennisbank taxonomy
- WHEN they create a new category "Duurzaamheid" under root with order 5
- THEN the category MUST appear in the category tree navigation
- AND the category MUST be available for article assignment
- AND categories MUST support up to 3 levels of hierarchy (root > level1 > level2)

#### Scenario: Empty category indication

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

- GIVEN an article "Procedure bouwvergunning" and zaaktype "Omgevingsvergunning bouwen"
- WHEN an editor links the article to the zaaktype via the `zaaktypeLinks` array property
- THEN the article MUST appear when an agent views a zaak of that type and clicks "Kennisbank"
- AND the link MUST be stored on the article as a zaaktype reference (UUID or identifier)

#### Scenario: View related articles from a case

- GIVEN an agent is viewing zaak "Bouwvergunning #2024-001" of type "Omgevingsvergunning bouwen"
- AND 3 kennisbank articles are linked to this zaaktype
- WHEN the agent clicks the "Kennisbank" button on the case view
- THEN the system MUST display the 3 related articles ordered by usefulness rating (highest first)
- AND the articles MUST be displayed in a dropdown or side panel

#### Scenario: Suggest articles during contact registration

- GIVEN an agent is registering a contactmoment with subject category "Vergunningen"
- WHEN the agent selects the subject category
- THEN the system MUST display a "Relevante artikelen" suggestion panel with articles tagged with the "Vergunningen" category
- AND the panel MUST show max 5 articles, ordered by popularity

---

### Requirement: Agent Feedback

The system MUST allow agents to rate articles for usefulness and suggest improvements, supporting continuous knowledge improvement (KCS methodology).

**Feature tier**: V1

#### Scenario: Rate article usefulness

- GIVEN an agent reads article "Hoe vraag ik een paspoort aan?" to answer a citizen question
- WHEN the agent clicks "Nuttig" (thumbs up) or "Niet nuttig" (thumbs down)
- THEN the system MUST create a `kennisfeedback` object in OpenRegister with: article UUID, rating, agent UID, timestamp
- AND the article's aggregate usefulness score MUST be recalculated
- AND the score MUST influence search result ranking (articles with higher scores rank higher)

#### Scenario: Suggest article improvement

- GIVEN an agent finds that article "Tarieven rijbewijs" contains outdated pricing
- WHEN the agent clicks "Suggestie" and enters "Tarieven zijn per 2024 gewijzigd, huidige prijzen kloppen niet"
- THEN the system MUST create a feedback object with rating "niet_nuttig" and the comment text
- AND kennisbank editors MUST receive a Nextcloud notification via `NotificationService` about the suggestion
- AND the feedback item MUST track status: nieuw, in behandeling, verwerkt

#### Scenario: View article feedback summary

- GIVEN article "Paspoort aanvragen" has 45 "nuttig" ratings and 5 "niet nuttig" ratings over the past month
- WHEN an editor views the article management page
- THEN the system MUST display: total views (estimated), thumbs up count (45), thumbs down count (5), satisfaction rate (90%), and latest improvement suggestions
- AND articles with satisfaction rate below 70% MUST be flagged for review

#### Scenario: Feedback-driven review workflow

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

- GIVEN an article "Escalatieprotocol agressieve burgers" with visibility "Intern"
- WHEN a citizen accesses the public knowledge base API
- THEN the article MUST NOT be returned by the API
- AND the article MUST only be visible to authenticated Nextcloud users with KCC role

#### Scenario: Public article via API

- GIVEN an article "Hoe vraag ik een paspoort aan?" with visibility "Openbaar"
- WHEN a citizen-facing application queries the public kennisbank API
- THEN the article MUST be returned with: title, summary, body, category, and tags
- AND internal-only fields (author UID, feedback data, zaaktype links) MUST NOT be included in the public response

#### Scenario: Mixed visibility in agent view

- GIVEN an agent searches the kennisbank and results include both public and internal articles
- WHEN the results are displayed
- THEN each article MUST show a visibility badge: "Openbaar" (green) or "Intern" (gray)
- AND the agent MUST be able to filter by visibility

---

### Requirement: Article Lifecycle Notifications

The system MUST notify relevant users about article lifecycle events to ensure knowledge stays current.

**Feature tier**: V1

#### Scenario: Review reminder for aging articles

- GIVEN a published article "Tarieven afvalstoffenheffing" was last updated 180 days ago
- AND the configured review interval is 180 days
- WHEN the background job checks for aging articles
- THEN the article author MUST receive a Nextcloud notification: "Artikel 'Tarieven afvalstoffenheffing' is 180 dagen niet bijgewerkt. Controleer of de inhoud nog actueel is."
- AND the article MUST show a "Review nodig" badge in the article list

#### Scenario: Notification on article archive

- GIVEN article "Oud parkeerbeleid" is archived by an editor
- AND 3 other articles link to "Oud parkeerbeleid"
- WHEN the archiving is saved
- THEN the editors of the 3 linking articles MUST receive a notification that a linked article has been archived
- AND the linking articles MUST show a warning about the broken link

#### Scenario: New article notification to team

- GIVEN a new article "Nieuwe regels energielabel" is published in category "Vergunningen"
- WHEN the article status changes to "Gepubliceerd"
- THEN agents subscribed to the "Vergunningen" category MUST receive a notification about the new article
- AND the notification MUST include the article title and a link

---

### Requirement: Article Analytics

The system MUST track article usage to help editors understand which articles are most valuable and which need improvement.

**Feature tier**: Enterprise

#### Scenario: Most-viewed articles report

- GIVEN the kennisbank has been active for 3 months
- WHEN an editor views the analytics dashboard
- THEN the system MUST display the top 20 most-viewed articles with view count, unique viewers, and average time on article
- AND articles with declining views MUST be highlighted

#### Scenario: Search terms without results report

- GIVEN agents have searched for 50 unique terms this month
- WHEN an editor views the "Ontbrekende kennis" report
- THEN the system MUST display search terms that returned zero results, ranked by frequency
- AND each term MUST show the number of times it was searched
- AND the editor MUST be able to click a term to create a new article pre-filled with the search term as title

#### Scenario: Article coverage by zaaktype

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

- GIVEN a KCC agent opens Pipelinq
- WHEN the agent clicks "Kennisbank" in the left navigation sidebar
- THEN the system MUST display the kennisbank home page with: search bar, category tree, recently updated articles, and popular articles
- AND the route MUST be `/apps/pipelinq/kennisbank`

#### Scenario: Article detail view

- GIVEN the agent clicks on article "Hoe vraag ik een paspoort aan?"
- WHEN the article detail page loads
- THEN the system MUST display: title, body (rendered Markdown), category breadcrumb, tags, last updated date, author name, version number, and related articles
- AND the page MUST include the feedback buttons (Nuttig/Niet nuttig) and a "Suggestie" link
- AND the page MUST include a "Terug naar zoekresultaten" link if the agent came from a search

#### Scenario: Keyboard navigation for accessibility

- GIVEN an agent is using the kennisbank with keyboard only
- WHEN the agent navigates via Tab key
- THEN the search field MUST be the first focusable element
- AND category tree items MUST be navigable with arrow keys
- AND all interactive elements MUST have visible focus indicators (WCAG AA)

---

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
