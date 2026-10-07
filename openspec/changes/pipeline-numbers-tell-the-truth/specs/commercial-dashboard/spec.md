# commercial-dashboard Specification (delta)

## MODIFIED Requirements

### Requirement: The weighted forecast uses each lead's win chance (REQ-PNT-001)

The weighted forecast SHALL be the sum over open leads of the lead value times
its win chance. A lead's win chance SHALL be its `qualificationScore` read as a
percentage, clamped to 0-100. A lead without a score SHALL count as 0. The
`probability` field SHALL NOT feed any forecast figure. The per-stage weighted
value in the reports SHALL use the same win chance. Lists that show a lead's
chance SHALL label it "Win chance".

#### Scenario: A scored tender adds to the forecast

- GIVEN an open lead worth 250000 with qualification score 65 and no probability
- WHEN the sales manager opens the dashboard
- THEN the weighted forecast includes 162500 for that lead

#### Scenario: A stray probability does not count

- GIVEN an open lead worth 1000 with probability 90 and no qualification score
- WHEN the forecast is computed
- THEN that lead adds 0

### Requirement: Revenue figures count won deals only (REQ-PNT-002)

The charts "Revenue over time", "Revenue by source" and "Top customers by
revenue" SHALL count won leads only. "Pipeline by stage" SHALL count open
leads only.

#### Scenario: An open tender is not revenue

- GIVEN an open tender lead for Gemeente Rotterdam worth 120000
- WHEN the sales manager reads "Top customers by revenue"
- THEN Gemeente Rotterdam does not appear for that lead

### Requirement: The pipeline gauge measures against your own target (REQ-PNT-003)

The "Open pipeline vs target" gauge SHALL format amounts in the reporting
currency and SHALL read its target from the app setting `pipeline_target`,
which an administrator sets on the forecast settings screen. With no target
set, the gauge SHALL say so and SHALL NOT show a percentage.

#### Scenario: An administrator sets a target

- GIVEN the reporting currency is USD
- WHEN the administrator sets the open pipeline target to 750000
- THEN the gauge shows the open pipeline against USD 750,000

#### Scenario: No target yet

- GIVEN no open pipeline target is set
- WHEN the sales manager opens the dashboard
- THEN the gauge reads "Open pipeline (no target set)" and shows no percentage
