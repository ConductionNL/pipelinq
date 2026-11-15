## ADDED Requirements

### Requirement: The caller chooser table is drawn as the board
The caller chooser MUST draw its matches within the dialog's content width, with long addresses wrapping, the name
column flush with the start of the content and the Select column flush with its end. The column heads MUST be 13px
semibold in soft ink on the dialog ground, and each name MUST be semibold.

#### Scenario: Three contacts match the caller
@e2e exclude Asserted in tests/vitest/callerChooserBoardTable.spec.js on the component's style; the pixel check against PqInkomendGesprek covers the drawn dialog.
- **GIVEN** three contacts match 078 612 33 41, one with a long address
- **WHEN** the chooser opens
- **THEN** the table MUST be as wide as the dialog's content and every Select button MUST be inside the dialog
