## ADDED Requirements

### Requirement: The receipt preview shows the text as typed

The print and email dialogs of a sale MUST show the receipt preview as the receipt text exactly as typed, in a fixed-width font, so the columns line up as on paper. While the preview loads they MUST show a spinner. Without a text they MUST say "No preview available".

#### Scenario: The columns line up

- GIVEN a sale of 2 loaves at 5,90
- WHEN the cashier opens Print receipt
- THEN the preview MUST show the receipt lines in a fixed-width font with their spacing kept

#### Scenario: No preview yet

- GIVEN the preview endpoint returns no text
- WHEN the cashier opens Email receipt
- THEN the preview MUST read "No preview available"
