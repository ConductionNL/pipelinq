# Marketing Segmentation and Blast — Docs

## ADDED Requirements

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
