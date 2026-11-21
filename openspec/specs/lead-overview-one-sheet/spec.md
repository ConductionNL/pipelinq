# lead-overview-one-sheet Specification

## Purpose
The lead page's Overview tab reads as one sheet: the stage strip, the deal, its products, its contacts and its flow runs as sections of one panel, with the product lines as plain text under sortable heads.

## Requirements

### Requirement: The lead overview is one sheet

The lead page's Overview tab MUST draw the stage strip, the deal, its products, its contacts and its flow runs as sections of one panel, the stage strip without a title.

#### Scenario: open a qualified lead

@e2e exclude Asserted in tests/vitest/leadOverviewOneSheet.spec.js; the pixel check against board PqLead covers the drawn sheet.

- GIVEN a lead in the Gekwalificeerd stage
- WHEN the lead page opens on Overzicht
- THEN the stage strip shows without a "Fasen" title
- AND Deal, Producten op deze lead, Contacten en hun rol and the flow runs follow in the same panel

### Requirement: The lead's product lines read as plain text under sortable heads

The products table MUST show each line's product in the main text at normal weight and MUST let the user sort and filter on its heads.

#### Scenario: sort the lines by price

@e2e exclude Asserted in tests/vitest/leadOverviewOneSheet.spec.js.

- GIVEN a lead with three product lines
- WHEN the user sorts on Prijs
- THEN the lines are ordered by price
