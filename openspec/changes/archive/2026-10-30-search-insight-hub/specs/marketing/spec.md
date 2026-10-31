# marketing: delta for search-insight-hub

## ADDED Requirements

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
