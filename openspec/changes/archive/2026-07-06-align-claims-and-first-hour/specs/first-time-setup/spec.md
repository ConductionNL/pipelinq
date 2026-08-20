# First-Time Setup — Demo Seed Delta

**Spec refs**: ADR-042 (setup wizard), `first-time-setup` change (SetupController action surface — referenced, not duplicated), procest `SeedBezwaarBeroepCommand` (`procest:bezwaar:seed`) pattern precedent
**Standards**: idempotent occ seeding; demo data explicitly marked and removable

## ADDED Requirements

### Requirement: REQ-SETUP-PIP-008 — Optional Demo-Data Seed

The system SHALL provide an idempotent demo-data seed invocable two ways from one write path: an occ command `pipelinq:demo:seed` (mirroring the procest `SeedBezwaarBeroepCommand` pattern, including explicit owner context because occ runs without a session) and an optional setup-wizard action (`seed-demo-data`, admin-only) per ADR-042. The seed SHALL create a small coherent linked dataset — clients (person and organisation), leads across pipeline stages, requests across statuses, and contactmomenten across channels — such that lists, dashboards, and the 360° client view render populated. Seeded objects SHALL be identifiable as demo data, re-running SHALL create no duplicates, and a removal mode SHALL delete exactly the seeded objects.

**Feature tier**: MVP

#### Scenario: Seed on a clean install

- GIVEN a clean install with provisioned registers
- WHEN `occ pipelinq:demo:seed` runs
- THEN clients, leads, requests, and contactmomenten MUST exist, linked so klantbeeld-360 and the dashboards render populated
- AND every seeded object MUST be identifiable as demo data

#### Scenario: Idempotent re-run

- GIVEN a completed seed
- WHEN the command runs again
- THEN no duplicate objects MUST be created

#### Scenario: Offered as an optional wizard step

- GIVEN an admin in the first-time setup wizard
- WHEN the optional steps are presented
- THEN a demo-data action MUST be offered, invoking the same seeding service as the occ command
- AND skipping it MUST NOT block setup completion

#### Scenario: Removal deletes exactly the seed

- GIVEN a seeded install with additional real data
- WHEN the removal mode runs
- THEN all seeded objects MUST be deleted and no real object MUST be touched
