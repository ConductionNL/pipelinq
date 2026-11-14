# request-management: delta for request-category-from-vocabulary

## ADDED Requirements

### Requirement: A request's category is picked from the tenant's category list

The request form MUST offer the tenant's active `ticketCategory` objects as a select, show each by its name and store its key in the ticket's `category`. When the tenant has no active category, the form MUST keep a free-text category field. A stored category that is not in the list MUST stay selectable, so saving the form never drops it.

#### Scenario: a tenant with categories

@e2e exclude Asserted in tests/vitest/requestCategoryVocabulary.spec.js; the pixel check against board PqNieuwVerzoek covers the drawn select.

- GIVEN the tenant has the active categories "Vergunningen" (key `vergunningen`) and "Afval" (key `afval`)
- WHEN the handler opens "Nieuw verzoek" and picks "Vergunningen" under Categorie
- THEN the ticket is saved with category `vergunningen`

#### Scenario: a tenant without categories

@e2e exclude Asserted in tests/vitest/requestCategoryVocabulary.spec.js.

- GIVEN the tenant has no active category
- WHEN the handler opens "Nieuw verzoek"
- THEN Categorie is a text field and what they type is saved as the category

#### Scenario: a ticket with a category outside the list

@e2e exclude Asserted in tests/vitest/requestCategoryVocabulary.spec.js.

- GIVEN a ticket stored with category `oud-onderwerp`, which no active category names
- WHEN the handler edits the ticket
- THEN Categorie shows `oud-onderwerp` selected
- AND saving keeps it
