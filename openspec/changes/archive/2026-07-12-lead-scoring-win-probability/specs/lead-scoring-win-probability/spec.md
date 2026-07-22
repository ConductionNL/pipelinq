## ADDED Requirements

### Requirement: Recency-decayed win probability is derived on every lead

The `lead` schema SHALL declare a `winProbability` calculation
(`x-openregister-calculations`) that yields an integer 0–100. The value SHALL be
the lead's `probability` decayed by inactivity, measured as the whole-day
difference between `@self.updated` and now:

- within 14 days of the last activity: `probability` (no decay)
- older than 14 and up to 30 days: 80% of `probability`
- older than 30 and up to 60 days: 50% of `probability`
- older than 60 days: 25% of `probability`

The calculation SHALL be `materialise: false` so it is recomputed on every read and
requires no write to stay current. It SHALL depend only on single-object inputs
(`@self.updated` and the lead's own `probability`) so it is expressible declaratively
per ADR-031 — the stage's base probability is already denormalised onto the
`probability` field, so no cross-schema join is needed. No service class SHALL be
introduced.

#### Scenario: Fresh lead keeps full probability
- **WHEN** a lead with `probability: 60` was last updated 3 days ago
- **THEN** its `winProbability` reads 60

#### Scenario: Stalled lead cools
- **WHEN** a lead with `probability: 60` has not been touched for 45 days
- **THEN** its `winProbability` reads 30 (50% band)

#### Scenario: Long-cold lead reads a quarter
- **WHEN** a lead with `probability: 80` has not been touched for 90 days
- **THEN** its `winProbability` reads 20 (25% band)

#### Scenario: Zero probability stays zero
- **WHEN** a lead has `probability: 0`
- **THEN** `winProbability` reads 0 regardless of age

### Requirement: Win probability is surfaced on the pipeline list and deal detail

The win probability SHALL be visible to a salesperson without any new frontend code:
it SHALL appear on the `LeadDetail` Deal data widget and as a column on the `Leads`
index in `src/manifest.json`, the index column reusing the existing
`lead-probability` cell widget for a colour-banded 0–100 rendering.

#### Scenario: Deal page shows win probability
- **WHEN** a user opens a lead's detail page
- **THEN** the Deal widget renders `winProbability` alongside `value`, `probability`, and `status`

#### Scenario: Pipeline list shows a colour-banded win probability column
- **WHEN** a user views the `Leads` index
- **THEN** a `winProbability` column renders each lead's decayed probability with the shared colour-banded cell widget, so cold deals read visually distinct from hot ones

### Requirement: Seed data spans the win-probability bands

The register seed SHALL include representative leads whose age and `probability`
place them across the hot / warm / cold bands, so the surfaced value is verifiable
on a fresh install.

#### Scenario: Seed leads render distinct bands
- **WHEN** the register seed is imported and the `Leads` index is opened
- **THEN** at least one recently-touched lead reads its full probability (hot) and at least one long-untouched lead reads a decayed value (cold)
