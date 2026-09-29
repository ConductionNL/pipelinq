# Delta for onboarding: Help page with courses and partners

## ADDED Requirements

### Requirement: Users can find where to learn (REQ-HELP-001)

The Help page MUST list the courses an administrator configured, each with title, level, language and an external link, MUST offer to replay the walkthrough, and MUST hide the Learn list when no course is configured.

#### Scenario: Course listed

- **GIVEN** an administrator added the course "Pipelinq basics", level beginner, Dutch
- **WHEN** Sanne opens Help
- **THEN** she sees "Pipelinq basics, beginner, Dutch" with a link that says it opens in a new tab

#### Scenario: No courses

- **GIVEN** no course is configured
- **WHEN** Sanne opens Help
- **THEN** the Learn list is not shown and the walkthrough replay and documentation link still are

### Requirement: Users can find a partner near them (REQ-HELP-002)

The Help page MUST list configured partners with name, province, services, website and contact email, MUST filter by province and service, and MUST include Conduction by default.

#### Scenario: Filter by province

- **GIVEN** the partners are Conduction (all provinces) and "Kantoor Noord" (Groningen)
- **WHEN** Pieter filters by Groningen
- **THEN** both partners that serve Groningen are shown

### Requirement: Only administrators change the lists (REQ-HELP-003)

Writing courses and partners MUST require administrator rights, and MUST refuse links that are not `https` and lists over 50 entries.

#### Scenario: Non-admin refused

- **GIVEN** Pieter is not an administrator
- **WHEN** he sends a change to the help content
- **THEN** the request is refused with 403

#### Scenario: Insecure link

- **GIVEN** an administrator adds a course with an `http` link
- **WHEN** she saves
- **THEN** the form shows that only https links are allowed and nothing is saved

