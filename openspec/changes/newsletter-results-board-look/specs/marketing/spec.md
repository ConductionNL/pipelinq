# marketing: delta for newsletter-results-board-look

## ADDED Requirements

### Requirement: The newsletter results page draws its tab panel as a card in the board look

In the board look the open tab's panel on the newsletter results page MUST be a white card joined to the open tab, and its tables MUST draw their head row on that card's ground.

#### Scenario: the Overview tab

@e2e exclude Asserted in tests/vitest/newsletterResultsBoardLook.spec.js; the pixel check against board PqNieuwsbriefPrestaties covers the drawn tab.

- GIVEN the board look
- WHEN the user opens the newsletter results page
- THEN the Overview table stands in a white card under the open tab
- AND its head row has no grey band

### Requirement: The site traffic of the blasts is read a few blasts at a time

The Toeschrijving tab MUST read the site traffic of the listed blasts with a bounded number of reads in flight, keep the blasts' order in its table, and stop starting reads once an answer says no portal is connected.

#### Scenario: many blasts

@e2e exclude Asserted in tests/vitest/newsletterResultsBoardLook.spec.js (readTrafficRows).

- GIVEN 46 listed blasts and a connected portal
- WHEN the user opens the Toeschrijving tab
- THEN at most six traffic reads are in flight at once
- AND the traffic table lists the blasts with site sessions in the order of the list
