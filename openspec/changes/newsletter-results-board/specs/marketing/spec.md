# marketing: delta for newsletter-results-board

## ADDED Requirements

### Requirement: The A/B tab names the variant that clicked better

When an A/B test has enough data for a verdict, the A/B tab MUST mark the variant with the higher click rate with a "Higher click rate" pill. It MUST also show the p-value of the difference.

#### Scenario: a decided test

@e2e exclude Asserted in tests/vitest/newsletterResultsBoard.spec.js; the verdict and p-value are covered by the A/B tests in tests/e2e/spec-coverage/marketing.spec.ts.

- GIVEN an A/B test of 203 and 201 delivered mails, sent more than a day ago, with click rates of 15% and 26%
- WHEN the user opens the A/B testing tab
- THEN variant B carries the "Higher click rate" pill
- AND the line under the cards gives the p-value

#### Scenario: older tests are summarised

@e2e exclude Asserted in tests/vitest/newsletterResultsBoard.spec.js; the pixel check against board PqNieuwsbriefABTest covers the drawn tab.

- GIVEN two A/B tests
- WHEN the user opens the A/B testing tab
- THEN the newest test is shown in full, with its variant cards
- AND the other test is one line: its name, both click rates and the verdict
