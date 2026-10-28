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
