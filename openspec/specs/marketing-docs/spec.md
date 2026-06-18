---
status: done
---

# marketing-docs Specification

## Purpose
Documents the marketing blast feature in the CHANGELOG and a user guide. The user guide covers creating segments and compliant templates, scheduling and sending blasts, A/B testing, and monitoring delivery and revenue attribution, and is available in Dutch and English.
## Requirements
### Requirement: Feature Documentation

The marketing blast feature SHALL be documented in the CHANGELOG and a
user guide.

#### Scenario: CHANGELOG entry added

- **GIVEN** the marketing blast feature
- **WHEN** the CHANGELOG is updated
- **THEN** it SHALL include an entry summarising rule-based segments, multi-channel sends, compliance enforcement, A/B testing, and revenue attribution

#### Scenario: User guide covers the workflow

- **GIVEN** `docs/user/marketing-blasts.md`
- **WHEN** a marketing manager reads it
- **THEN** it SHALL cover creating segments, creating compliant templates, scheduling/sending blasts, A/B testing, and monitoring delivery + attribution
- **AND** SHALL be available in Dutch and English

