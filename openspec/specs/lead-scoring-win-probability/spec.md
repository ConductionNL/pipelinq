---
status: in-progress
---

# Spec: Lead Scoring — Win Probability

**OpenSpec changes**: [lead-scoring-win-probability](../../changes/lead-scoring-win-probability/) _(in-progress)_

## Purpose

Defines a recency-decayed win probability derived on the `lead` schema and surfaced on
the pipeline list and deal detail. `winProbability` is a declarative
`x-openregister-calculations` field (`materialise: false`) equal to the lead's
stage-denormalised `probability` decayed by inactivity — full within 14 days of the
last touch, then stepped down at 30/60 days — so a stalling deal visibly cools on read
with no write. It complements, and does not replace, the existing `qualificationScore`
and `weightedValue` calculations. Surfacing is purely declarative (manifest data
widget + a colour-banded index column reusing the existing `lead-probability` cell
widget) — no service class, no new schema, no PHP (ADR-031/ADR-032, `kind: config`).

**Standards**: Schema.org (`Demand`); industry CRM win-probability consensus (Odoo,
Dolibarr, Salesforce Einstein, Zoho, HubSpot)
**Primary feature tier**: V1

## Requirements

### Requirement: Recency-decayed win probability is derived on every lead

The `lead` schema SHALL declare a `winProbability` calculation
(`x-openregister-calculations`, integer 0–100, `materialise: false`) equal to the
lead's `probability` decayed by inactivity: full within 14 days of the last activity,
80% up to 30 days, 50% up to 60 days, 25% beyond. It SHALL depend only on
single-object inputs (`@self.updated`, `probability`) — no service class.

#### Scenario: Stalled lead cools
- **WHEN** a lead with `probability: 60` has not been touched for 45 days
- **THEN** its `winProbability` reads 30 (50% band)

#### Scenario: Fresh lead keeps full probability
- **WHEN** a lead with `probability: 60` was last updated 3 days ago
- **THEN** its `winProbability` reads 60

### Requirement: Win probability is surfaced declaratively

The win probability SHALL appear on the `LeadDetail` Deal data widget and as a
colour-banded column on the `Leads` index in `src/manifest.json` (reusing the existing
`lead-probability` cell widget), with no new frontend code.

#### Scenario: Pipeline list shows a colour-banded win probability column
- **WHEN** a user views the `Leads` index
- **THEN** a `winProbability` column renders each lead's decayed probability with the shared colour-banded cell widget

The full requirements and scenarios are maintained in the change delta at
[`changes/lead-scoring-win-probability/specs/lead-scoring-win-probability/spec.md`](../../changes/lead-scoring-win-probability/specs/lead-scoring-win-probability/spec.md)
and are folded into this spec on archive.
