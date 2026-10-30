# commercial-dashboard Delta

## ADDED Requirements

### Requirement: The commercial overview counts the deals behind its figures

The commercial overview SHALL return `wonCount` and `lostCount` for the window (and in `previousPeriod`),
`openCount` and `scoredOpenCount` for the open pipeline, and `wonShareOfOpen`, the won value as a whole percentage of
the open value (null when nothing is open).

#### Scenario: Two won, one lost, four open of which three scored

- **WHEN** the window holds two won deals and one lost deal, and four leads are open, three with a qualification score
- **THEN** the overview returns `wonCount` 2, `lostCount` 1, `openCount` 4 and `scoredOpenCount` 3

### Requirement: The commercial overview gives the pipeline cover

With a pipeline target set, the overview SHALL return `targetRemaining`, the target less the won value of the window
(not below 0), and `pipelineCover`, the open value over that remainder to one decimal. Without a target both SHALL be
null; with the target met `pipelineCover` SHALL be null.

#### Scenario: Target 9000, won 4000, open 7000

- **WHEN** the target is 9000, the window's won value 4000 and the open value 7000
- **THEN** `targetRemaining` is 5000 and `pipelineCover` is 1.4
