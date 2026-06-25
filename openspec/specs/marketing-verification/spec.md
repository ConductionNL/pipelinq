---
status: done
---

# marketing-verification Specification

## Purpose
Defines the end-to-end verification and pre-merge checklist for the marketing blast feature. Requires the unit and integration suites to pass with at least 80% service coverage, the full draft-to-sent send workflow, compliance blocking, A/B, and unsubscribe behaviour to be verified, and a security checklist confirming pattern invariants such as ObjectService-only CRUD, consent gating on every send, and template enforcement.
## Requirements
### Requirement: End-to-End Verification Passes

The marketing blast feature SHALL be verified end to end before the chain is
considered complete.

#### Scenario: Tests green with coverage

- **GIVEN** the unit and integration suites
- **WHEN** `composer test` runs
- **THEN** all tests SHALL pass and service coverage SHALL be at least 80%

#### Scenario: Full send workflow verified

- **GIVEN** a test segment, compliant template, and contacts with consent
- **WHEN** a blast is sent
- **THEN** the blast SHALL progress draft → sending → sent and BlastDeliveries SHALL be created per compliant contact

#### Scenario: Compliance blocking verified

- **GIVEN** a contact without email consent in the segment
- **WHEN** a send is attempted
- **THEN** the send SHALL be blocked with a missing-consent modal and the skip option SHALL work
- **AND** a template without an unsubscribe token SHALL be rejected on save

#### Scenario: A/B and unsubscribe verified

- **GIVEN** an A/B blast and a simulated unsubscribe webhook
- **WHEN** verification runs
- **THEN** both variants SHALL be created and the significance test SHALL render once thresholds are met
- **AND** the unsubscribe SHALL withdraw consent within 60 seconds and future sends SHALL skip the contact

### Requirement: Pre-Merge Security Checklist

The pre-merge checklist SHALL confirm the security and pattern invariants
before the PR is submitted.

#### Scenario: Checklist invariants confirmed

- **GIVEN** the completed feature
- **WHEN** the pre-merge checklist is walked
- **THEN** it SHALL confirm ObjectService-only CRUD, IUserSession-derived identity, generic error messages, thin controllers, async webhook processing, consent gating on every send, template enforcement, per-source rate limiting, attribution temporal order, Dutch seed data, and `@spec` PHPDoc coverage

