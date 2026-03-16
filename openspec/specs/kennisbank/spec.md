# Kennisbank Specification

## Purpose

The kennisbank (knowledge base) provides KCC agents with a searchable repository of articles, FAQs, and procedures to answer citizen questions quickly and consistently. Articles are categorized, versioned, and linked to zaaktypen so agents can find the right information for each type of inquiry. This capability appears in KCS (Knowledge-Centered Service) and CRM tender requirements, and is a key enabler for first-call resolution.

**Standards**: Schema.org (`Article`, `FAQPage`, `HowTo`), KCS (Knowledge-Centered Service) methodology
**Feature tier**: V1 (core), Enterprise (AI-assisted search, analytics)
**Tender frequency**: Explicitly referenced in 1/52 KCC tenders, but implicitly required by many tenders demanding high first-call resolution rates (74%+ FCR targets appear in 51/52 tenders)

## Data Model

Knowledge base articles are stored as OpenRegister objects in the `pipelinq` register:
- **Article**: title, body (rich text), category, tags, zaaktype links, visibility (public/internal), status (draft/published/archived), version, author, last updated
- **Category**: hierarchical taxonomy for organizing articles
- **Feedback**: per-article usefulness ratings from agents

## Requirements

---

### Requirement: Article Management

The system MUST support creating, editing, publishing, and archiving knowledge base articles with rich text content.

**Feature tier**: V1

#### Scenario: Create a new article

- GIVEN a kennisbank editor with appropriate permissions
- WHEN they create an article with title "Hoe vraag ik een paspoort aan?", category "Burgerzaken", body content with formatted text and links, and visibility "Public"
- THEN the system MUST create an OpenRegister object with the `kennisartikel` schema
- AND the article MUST have status "Concept" (draft) initially
- AND the article MUST store the author identity and creation timestamp

#### Scenario: Publish an article

- GIVEN a draft article "Hoe vraag ik een paspoort aan?"
- WHEN an editor changes the status to "Gepubliceerd"
- THEN the article MUST become visible to all KCC agents in search results
- AND the publication date MUST be recorded
- AND if the article is marked "Public", it MUST also be available for citizen-facing channels

#### Scenario: Edit a published article (versioning)

- GIVEN a published article "Hoe vraag ik een paspoort aan?" at version 1
- WHEN an editor modifies the body text and saves
- THEN the system MUST create version 2 of the article
- AND version 1 MUST be retained in the version history
- AND the "Laatst bijgewerkt" date MUST update to the current timestamp
- AND the version history MUST show who made each change

#### Scenario: Archive an obsolete article

- GIVEN a published article "Oud beleid afvalscheiding" that is no longer relevant
- WHEN an editor sets the status to "Gearchiveerd"
- THEN the article MUST no longer appear in default search results
- AND the article MUST still be accessible via "Toon gearchiveerd" filter
- AND links to this article from other articles MUST show a "Gearchiveerd" badge

---

### Requirement: Search and Discovery

The system MUST provide fast, full-text search across all published articles to help agents find answers during live contacts.

**Feature tier**: V1

#### Scenario: Full-text search

- GIVEN 200 published articles in the kennisbank
- WHEN an agent searches for "paspoort verlengen"
- THEN the system MUST return relevant articles ranked by relevance
- AND search MUST cover title, body text, and tags
- AND results MUST display: title, category, relevance score, and a text snippet with highlighted matches

#### Scenario: Search with zero results

- GIVEN an agent searches for "kwarktaart recept"
- WHEN no articles match the query
- THEN the system MUST display "Geen resultaten gevonden"
- AND the system SHOULD suggest: "Probeer andere zoektermen" or show related categories

#### Scenario: Search during active contact

- GIVEN an agent is handling a phone call in the KCC werkplek
- WHEN the agent types a search query in the kennisbank search panel
- THEN results MUST appear within 500ms (while the citizen is on the phone)
- AND the agent MUST be able to view an article without leaving the KCC werkplek context

---

### Requirement: Categorization and Taxonomy

The system MUST support hierarchical categories for organizing articles and enabling browsable navigation.

**Feature tier**: V1

#### Scenario: Browse articles by category

- GIVEN categories: "Burgerzaken" (with subcategories "Paspoort", "Rijbewijs", "Uittreksel"), "Belastingen", "Vergunningen"
- WHEN an agent browses the category "Burgerzaken > Paspoort"
- THEN the system MUST display all articles in the "Paspoort" subcategory
- AND the breadcrumb navigation MUST show: Kennisbank > Burgerzaken > Paspoort

#### Scenario: Article in multiple categories

- GIVEN an article "Verhuizing doorgeven" relevant to both "Burgerzaken" and "Belastingen"
- WHEN an editor assigns both categories
- THEN the article MUST appear in both category views
- AND removing from one category MUST NOT affect the other

---

### Requirement: Zaaktype Linking

The system MUST support linking articles to specific zaaktypen, so agents handling a particular type of case can quickly find relevant knowledge.

**Feature tier**: V1

#### Scenario: Link article to zaaktype

- GIVEN an article "Procedure bouwvergunning" and zaaktype "Omgevingsvergunning bouwen"
- WHEN an editor links the article to the zaaktype
- THEN the article MUST appear when an agent views a zaak of that type and clicks "Kennisbank"
- AND the link MUST be stored on the article as a zaaktype reference

#### Scenario: View related articles from a case

- GIVEN an agent is viewing zaak "Bouwvergunning #2024-001" of type "Omgevingsvergunning bouwen"
- AND 3 kennisbank articles are linked to this zaaktype
- WHEN the agent clicks the "Kennisbank" button on the case view
- THEN the system MUST display the 3 related articles
- AND the articles MUST be ordered by relevance/usefulness rating

---

### Requirement: Agent Feedback

The system MUST allow agents to rate articles for usefulness and suggest improvements, supporting continuous knowledge improvement (KCS methodology).

**Feature tier**: V1

#### Scenario: Rate article usefulness

- GIVEN an agent reads article "Hoe vraag ik een paspoort aan?" to answer a citizen question
- WHEN the agent clicks "Nuttig" (thumbs up) or "Niet nuttig" (thumbs down)
- THEN the system MUST record the rating with agent identity and timestamp
- AND the article's aggregate usefulness score MUST be updated
- AND the score MUST influence search result ranking

#### Scenario: Suggest article improvement

- GIVEN an agent finds that article "Tarieven rijbewijs" contains outdated pricing
- WHEN the agent clicks "Suggestie" and enters "Tarieven zijn per 2024 gewijzigd, huidige prijzen kloppen niet"
- THEN the system MUST create a feedback item linked to the article
- AND kennisbank editors MUST receive a notification about the suggestion
- AND the feedback item MUST track status: nieuw, in behandeling, verwerkt

---

### Requirement: Public vs Internal Articles

The system MUST distinguish between articles visible only to agents (internal) and articles also available for citizen-facing channels (public).

**Feature tier**: V1

#### Scenario: Internal-only article

- GIVEN an article "Escalatieprotocol agressieve burgers" with visibility "Intern"
- WHEN a citizen accesses the public knowledge base
- THEN the article MUST NOT be visible or searchable
- AND the article MUST only be visible to authenticated KCC agents

#### Scenario: Public article

- GIVEN an article "Hoe vraag ik een paspoort aan?" with visibility "Openbaar"
- WHEN a citizen accesses the public knowledge base (if available)
- THEN the article MUST be visible and searchable
- AND internal notes or annotations on the article MUST NOT be shown to citizens
