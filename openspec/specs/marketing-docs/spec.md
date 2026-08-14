---
status: done
---

# marketing-docs Specification

## Purpose
Documents the marketing blast feature in the CHANGELOG and a user guide. The user guide covers creating segments and compliant templates, scheduling and sending blasts, A/B testing, and monitoring delivery and revenue attribution, and is available in Dutch and English.
## Requirements
### Requirement: Feature Documentation

@e2e exclude both scenarios assert the CONTENT OF FILES IN THIS REPOSITORY — a
CHANGELOG entry and `docs/user/marketing-blasts.md` — not any behaviour of a
running instance. Nothing a browser can do to a Nextcloud changes what those
files say, so an e2e test could not tell a documented feature from an
undocumented one. Verified present rather than assumed: `docs/user/marketing-blasts.md`
and `docs/user/marketing-blasts.nl.md` both exist (that pair IS the Dutch/English
requirement), and `CHANGELOG.md` carries the marketing-blast entry including the
revenue-attribution line. The honest enforcement for a documentation requirement
is a file check in CI, not a browser.

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

