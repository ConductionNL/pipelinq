# marketing Specification

## Purpose
The marketing result and insight pages: newsletter results with their A/B variants, and the search insight hub with its sections.

## Requirements

### Requirement: The A/B tab names the variant that clicked better

When an A/B test has enough data for a verdict, the A/B tab MUST mark the variant with the higher click rate with a "Higher click rate" pill. It MUST also show the p-value of the difference.

#### Scenario: a decided test

@e2e exclude Asserted in tests/vitest/newsletterResultsBoard.spec.js; the verdict and p-value are covered by the A/B tests in tests/e2e/spec-coverage/marketing.spec.ts.

- GIVEN an A/B test of at least 500 delivered mails per variant, sent more than a day ago, with click rates of 15% and 26%
- WHEN the user opens the A/B testing tab
- THEN variant B carries the "Higher click rate" pill
- AND the line under the cards gives the p-value

#### Scenario: a test without enough data

@e2e exclude Asserted in tests/vitest/newsletterResultsBoard.spec.js; the pending state is covered by the A/B tests in tests/e2e/spec-coverage/marketing.spec.ts.

- GIVEN an A/B test of 203 and 201 delivered mails
- WHEN the user opens the A/B testing tab
- THEN no variant carries the "Higher click rate" pill
- AND the line under the cards says the results are not yet available, with the current counts

#### Scenario: older tests are summarised

@e2e exclude Asserted in tests/vitest/newsletterResultsBoard.spec.js; the pixel check against board PqNieuwsbriefABTest covers the drawn tab.

- GIVEN two A/B tests
- WHEN the user opens the A/B testing tab
- THEN the newest test is shown in full, with its variant cards
- AND the other test is one line: its name, both click rates and the verdict

#### Scenario: each variant shows the subject it was sent with

@e2e exclude Asserted in tests/vitest/newsletterResultsBoard.spec.js; the pixel check against board PqNieuwsbriefABTest covers the drawn subject lines.

- GIVEN an A/B test whose variant B sent a different template than variant A
- WHEN the user opens the A/B testing tab
- THEN each variant card shows "Onderwerp:" with the subject of the template that variant sent
- AND a variant whose template cannot be read shows no subject line

### Requirement: The search insight page shows the four search pages as sections

The search insight page MUST show, in this order, the search queries, the keyword proposals, the competitor watches and the connection audit. Each MUST appear as a titled section on one page.

#### Scenario: open search insight

@e2e exclude Asserted in tests/vitest/searchInsightHub.spec.js; the pixel check against board PqZoekinzicht covers the drawn page, and the heading Search queries stays covered by marketing-campaign-attribution.spec.ts.

- GIVEN imported search queries, keyword proposals, two competitor watches and a stored connection audit
- WHEN the user opens Search insight under Marketing
- THEN the page shows the sections Search queries, Keywords, Competitors and Connection audit
- AND Check again in the Connection audit section re-runs the audit

#### Scenario: the competitor watches as one table

@e2e exclude Asserted in tests/vitest/competitorWatchesDutch.spec.js and tests/vitest/searchInsightHub.spec.js; the pixel check against board PqZoekinzicht covers the drawn section.

- GIVEN two competitor watches, one read and one that could not be reached, and one seen item
- WHEN the user opens Search insight
- THEN the Competitors section shows one table with the competitor, the kind it follows, how often, the last run, the outcome as a status pill and a Run now button
- AND one line under the table names the newest item a competitor published
- AND the full Competitors page still shows each watch's target and every seen item

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

### Requirement: The newsletter list names each newsletter's audience

The newsletter list MUST show, under each newsletter's name, the segment it goes to with the segment's estimated size, or else the mailing list it goes to with its number of confirmed subscribers.

#### Scenario: a segment and a list

@e2e exclude Asserted in tests/vitest/newsletterListAudienceLine.spec.js (the config) and nc-vue's CnDataTableSecondaryRefs.spec.js (the rendering); the pixel check against board PqNieuwsbrieven covers the drawn line.

- GIVEN "Straatfeestseizoen: wat regel je" sent to segment "Verenigingen in Zuiddrecht" (estimated 412)
- AND "Afvalkalender 2027" sent to mailing list "Afvalnieuws"
- WHEN the user opens Nieuwsbrieven
- THEN the first reads "Segment: verenigingen in Zuiddrecht · 412 contacten" under its name
- AND the second reads "Mailinglijst: Afvalnieuws · <confirmed subscribers> abonnees"
