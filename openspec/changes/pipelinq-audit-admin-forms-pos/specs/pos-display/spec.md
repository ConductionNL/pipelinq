# POS display: audit fixes

Delta over `specs/pos-nl-btw-engine` and `specs/pos-transaction-core`.

## ADDED Requirements

### Requirement: POS amounts and labels follow the user

POS amounts SHALL be formatted in the setup currency and the user's own locale. The VAT rate names on screen SHALL be translated (English "Standard rate (21%)", Dutch "Standaardtarief (21%)"); the Dutch GL descriptions stored for bookkeeping stay as they are. The POS menu and page titles SHALL read in the user's language. A tender SHALL show its tender type's name, never its uuid. A product's unit price and cost SHALL show with a currency, and the product's Used on deals table SHALL name the lead.

#### Scenario: VAT split in English

- GIVEN a user whose language is English
- WHEN the user opens a POS transaction with a 21% line
- THEN the invoice split SHALL read "Standard rate (21%)", not "Standaardtarief (21%)"

@e2e exclude a translated label on a computed table, asserted by tests/vitest/posTotals.spec.js (rateLabel).

#### Scenario: Amounts in the setup currency and locale

- GIVEN the setup currency is USD and the user's locale is en-US
- WHEN the POS formats 1234.5
- THEN it SHALL read "$1,234.50"

@e2e exclude pure formatting, asserted by tests/vitest/posTotals.spec.js (formatEur).

#### Scenario: Tender type by name

- GIVEN a tender whose type is not in the active tender type list
- WHEN the POS transaction detail shows its tenders
- THEN the tender type column SHALL show the type's name, looked up by id

@e2e exclude asserted by tests/vitest/posDisplay.spec.js with the real TenderEntryPanel.

#### Scenario: Product price and deal lines

- GIVEN a product with a unit price that is used on a deal line
- WHEN a user opens the product
- THEN the unit price SHALL show with a currency
- AND the Used on deals table SHALL show the lead's title

@e2e exclude manifest configuration over nextcloud-vue widgets, asserted by tests/vitest/posDisplay.spec.js.

#### Scenario: POS menu in English

- GIVEN a user whose language is English
- WHEN the user opens the POS menu
- THEN it SHALL read Receipts, Returns, Cash drawer and Cash register audit, and a transaction page SHALL be titled Transaction

@e2e tests/e2e/spec-coverage/pipelinq-pos-grouping.spec.ts
