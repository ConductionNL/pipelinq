# product-catalog-quoting: delta for quote-page-leftovers

## ADDED Requirements

### Requirement: The quote page shows every field of the quote card and a history in words

The quote detail page MUST show all eight fields of the Offerte card without a "Show all fields" fold, and its History card MUST describe each entry in words on compact rows.

#### Scenario: a draft quote

@e2e exclude Asserted in tests/vitest/quotesBoardLook.spec.js; the pixel check against board PqOfferte covers the drawn cards.

- GIVEN a draft quote made from a lead
- WHEN the user opens the quote
- THEN the Offerte card shows Lead, Klant, Contact, Titel, Verzenddatum, Vervaldatum, Toegewezen aan and Betalingstermijn
- AND the History card lists "Aangemaakt door" with the author for the creation and "Bijgewerkt" for a change
- AND a status change to sent, accepted, rejected or ordered reads as that status
