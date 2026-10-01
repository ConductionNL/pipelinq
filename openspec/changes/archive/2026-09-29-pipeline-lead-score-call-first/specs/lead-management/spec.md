# Delta for lead-management: score on the list and the board

## ADDED Requirements

### Requirement: The lead list shows and sorts by score (REQ-LSCORE-001)

The Leads list MUST have a sortable "Score" column showing the stored qualification score with a text band label, and a "Call first" quick filter that orders open leads by score descending.

#### Scenario: Call first

- **GIVEN** three open leads score 85, 40 and 10
- **WHEN** Sanne opens Leads and chooses "Call first"
- **THEN** the leads appear in the order 85, 40, 10 and the labels read High, Medium, Low

#### Scenario: Old lead without a score

@e2e exclude OpenRegister calculates the score on every save, so no lead without one can be created on a live instance; the dash is asserted in tests/vitest/leadScoreBadge.spec.js (LeadScoreCell shows a dash for a lead saved before the score existed)

- **GIVEN** a lead saved before the score existed has no stored score
- **WHEN** Sanne views the list
- **THEN** its Score cell shows a dash

### Requirement: The board card shows the score (REQ-LSCORE-002)

A lead card on the pipeline board MUST show the score as a number with an accessible name that includes its band, and the board table MUST be sortable by score.

#### Scenario: Card badge

- **GIVEN** a lead has a score of 92
- **WHEN** Pieter views the board
- **THEN** its card shows "92" and a screen reader announces "Score 92, high"

### Requirement: A person can see why a lead has its score (REQ-LSCORE-003)

The score badge MUST open an explanation listing each criterion that added points and how many, and MUST say when the listed total differs from the stored score.

#### Scenario: Explain 35

- **GIVEN** a lead with a value, a linked client and a close date has score 35
- **WHEN** Sanne opens the explanation
- **THEN** it lists "Value present +10", "Client linked +15", "Expected close date set +10" and totals 35

