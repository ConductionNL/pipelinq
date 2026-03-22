# Kennisbank - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Article Management

The system MUST support creating, editing, publishing, and archiving knowledge base articles.

#### Scenario: Create a new article
- GIVEN a kennisbank editor with appropriate permissions
- WHEN they create an article with title, category, body content, and visibility
- THEN the system MUST create an OpenRegister object with the kennisartikel schema
- AND the article MUST have status "Concept" initially

#### Scenario: Publish an article
- GIVEN a draft article
- WHEN an editor changes status to "Gepubliceerd"
- THEN the article MUST become visible to all KCC agents in search results

### Requirement: Category Hierarchy

The system MUST support hierarchical categories for organizing knowledge base articles.

#### Scenario: Create nested categories
- GIVEN a top-level category "Burgerzaken"
- WHEN an admin creates a sub-category "Paspoorten" with parent "Burgerzaken"
- THEN the category tree MUST show "Paspoorten" nested under "Burgerzaken"

### Requirement: Full-Text Search

The system MUST provide full-text search across article titles, bodies, and tags.

#### Scenario: Search articles by keyword
- GIVEN published articles about paspoort, rijbewijs, and parkeervergunning
- WHEN an agent searches for "paspoort"
- THEN the search results MUST include the paspoort article ranked by relevance
