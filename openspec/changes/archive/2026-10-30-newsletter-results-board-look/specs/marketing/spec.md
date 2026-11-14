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

### Requirement: The A/B variant cards draw their figures as the board does

On the A/B testing tab each variant card MUST show the subject of the template it sent, and its delivered, clicked and click-rate figures MUST stand directly under their labels, without Nextcloud's global definition-list box. The winning variant MUST carry a bordered card in the success badge ink and a "Higher click rate" pill with a check icon. In the board look the back link and the tab panes MUST set their text on a 1.2 line.

#### Scenario: a decided A/B test

@e2e exclude Asserted in tests/vitest/newsletterResultsBoardLook.spec.js; the pixel check against boards PqNieuwsbriefABTest and PqNieuwsbriefToeschrijving covers the drawn cards.

- GIVEN an A/B pair with 500 or more delivered per variant, sent more than a day ago
- WHEN the user opens the A/B testing tab
- THEN each variant card shows "Subject:" with the subject of its template
- AND the figures stand under their labels at 2px
- AND the variant with the higher click rate has a green border and a pill with a check icon
