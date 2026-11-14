# side-figures-and-diff-head

## ADDED Requirements

### Requirement: A side card's figure is drawn at the board size

The opening float on a cash shift and the win chance on a lead MUST be drawn as 26px figures in their side cards.

#### Scenario: open a counted shift

@e2e exclude Asserted in tests/vitest/sideFiguresAndDiffHead.spec.js; the pixel check against boards PqKassadienst and PqLead covers the drawn size.

- GIVEN a cash shift with an opening float of 100 euro
- WHEN the shift page opens
- THEN the Beginsaldo card shows "€ 100,00" at 26px

### Requirement: The cash difference card names the reconciliation and draws no pending pill

The cash difference card MUST carry the line "Afstemming" under its title. While the difference waits for a manager the card MUST draw no header pill; an approved or rejected difference MUST keep its pill.

#### Scenario: a difference outside the margin waits

@e2e exclude Asserted in tests/vitest/sideFiguresAndDiffHead.spec.js.

- GIVEN a counted shift whose difference is pending
- WHEN the shift page opens
- THEN the Kasverschil card reads "Afstemming" under its title
- AND no "In behandeling" pill shows in its header

#### Scenario: an approved difference

@e2e exclude Asserted in tests/vitest/sideFiguresAndDiffHead.spec.js.

- GIVEN a counted shift whose difference is approved
- WHEN the shift page opens
- THEN the Kasverschil card shows the "Goedgekeurd" pill
