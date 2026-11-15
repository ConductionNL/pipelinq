# klachtenregistratie Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `req-complaint`.

## ADDED Requirements

### Requirement: A complaint is held to the legal answer term by default

The system SHALL give a complaint an answer deadline at the legal answer term counted from receipt when its category has no shorter term configured.

#### Scenario: A complaint in a category without its own term

- GIVEN a complaint category with no configured answer term
- WHEN an agent registers a complaint in that category
- THEN the complaint shows an answer deadline six weeks after its receipt date
- AND it is marked overdue once that date passes without an answer
