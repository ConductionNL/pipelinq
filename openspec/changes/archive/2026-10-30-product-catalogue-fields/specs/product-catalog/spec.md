# product-catalog: delta for product-catalogue-fields

## ADDED Requirements

### Requirement: The product page shows the tariffs in force

A product MAY carry tariffs with a label, amount, valid-from date and note. The product page MUST show per label the tariff whose valid-from date is the latest on or before today, an amount of zero as "Free", the tariff source under the table, and the first later valid-from date as a scheduled publication.

#### Scenario: next year's tariffs are scheduled

- GIVEN the waste pass has a replacement tariff of 15 from 1 January 2026 and of 16 from 1 January 2027
- WHEN the product page opens on 7 October 2026
- THEN the table shows the replacement tariff at 15
- AND a note says the tariffs of 2027 go live on 1 January 2027

### Requirement: The product page shows its publication

The publication card MUST show the status, the publication date with the month written out, the languages in capitals and the audience as one phrase.

#### Scenario: a product for residents and businesses

- GIVEN a product published on 1 January 2026 in nl and en for residents and businesses
- THEN the card reads "1 januari 2026", "NL, EN" and "Inwoner, bedrijf"

### Requirement: A light item's weight reads in grams

The supply card MUST show a weight under one kilogram in grams, from `weightGrams` when set, else from `weight` in kilograms.

#### Scenario: a card weighing 5 grams

- GIVEN a product with `weightGrams` 5
- THEN the supply card reads "5 g"
