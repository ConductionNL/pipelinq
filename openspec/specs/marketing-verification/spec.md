---
status: done
---

# marketing-verification Specification

## Purpose
Defines the end-to-end verification and pre-merge checklist for the marketing blast feature. Requires the unit and integration suites to pass with at least 80% service coverage, the full draft-to-sent send workflow, compliance blocking, A/B, and unsubscribe behaviour to be verified, and a security checklist confirming pattern invariants such as ObjectService-only CRUD, consent gating on every send, and template enforcement.
## Requirements
### Requirement: End-to-End Verification Passes

@e2e exclude this is a PRE-MERGE VERIFICATION CHECKLIST, not a behavioural
requirement — three of its four scenarios have "WHEN verification runs" or
"WHEN `composer test` runs" as their trigger, i.e. their subject is the release
process, and one ("Tests green with coverage") asserts a coverage percentage,
which no browser can observe. The behaviour the remaining items re-state is
already specified — and covered — in the capabilities that own it:
`marketing-compliance` ("Send blocked with missing consent list", "Save
rejected if unsubscribe token missing", "Withdrawal updates consent and skips
queued deliveries"), `marketing-ui` ("Missing-consent modal on send"),
`marketing-blast-delivery` (the unsubscribe webhook) and `marketing-analytics`
(the significance test). All of those headings were read to confirm the overlap
before this was written. Anchoring a second e2e test here would assert the same
behaviour twice under a heading that is not its canonical home, and the two
copies would drift.

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

@e2e exclude the subject here is a CHECKLIST COMPLETED BEFORE A PR IS
SUBMITTED — "the pre-merge checklist SHALL confirm …" — so the thing required
to happen occurs outside any running instance and before the code is merged. A
browser test cannot observe whether a reviewer ticked a box. The pattern
invariants it lists are the ones already enforced mechanically on every PR by
the hydra gate suite (ObjectService-only CRUD by gate-20 `or-objectservice-api`
and gate-23 `or-abstraction-anti-patterns`; the auth posture of every route by
gates 5, 7, 9; consent gating and template enforcement by the
`marketing-compliance` capability's own scenarios) — enforcement that runs
whether or not anyone remembers the checklist, which is strictly stronger than
the checklist itself.

The pre-merge checklist SHALL confirm the security and pattern invariants
before the PR is submitted.

#### Scenario: Checklist invariants confirmed

- **GIVEN** the completed feature
- **WHEN** the pre-merge checklist is walked
- **THEN** it SHALL confirm ObjectService-only CRUD, IUserSession-derived identity, generic error messages, thin controllers, async webhook processing, consent gating on every send, template enforcement, per-source rate limiting, attribution temporal order, Dutch seed data, and `@spec` PHPDoc coverage

