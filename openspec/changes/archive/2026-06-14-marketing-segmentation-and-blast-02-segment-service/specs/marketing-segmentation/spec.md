# Marketing Segmentation and Blast — Segment Service

## ADDED Requirements

### Requirement: Segment Builder Composes Rule Trees

The segment service SHALL validate rule trees using AND/OR logic with leaf
predicates (field, operator, value). Each predicate SHALL be validated
against the entity schema before save.

#### Scenario: Rule tree validated on save

- **GIVEN** a rule `industry = "gemeente" AND (employees > 50 OR annual_revenue > 5000000) AND last_contact_moment < 90 days`
- **WHEN** the segment is saved
- **THEN** the system SHALL serialize the rule tree as JSON and call `SegmentService.validateRules()` to verify each leaf predicate (field exists, operator valid for type, value coercible)
- **AND** on validation success SHALL save a Segment object with the rule tree
- **AND** on validation failure SHALL return field-level errors and block save

#### Scenario: Estimated size computed

- **GIVEN** a validated rule tree
- **WHEN** the segment detail is requested
- **THEN** the system SHALL return the count from `SegmentService.estimateSize()`
- **AND** the estimate SHALL be cached (default 1 hour TTL) to avoid repeated full-table scans

#### Scenario: Operators validated per field type

- **GIVEN** a contact schema with `industry` (string), `employees` (integer), `last_contact_moment` (date)
- **WHEN** a predicate `industry > 50` is validated (string field with numeric operator)
- **THEN** `validateRules()` SHALL reject the predicate with an operator-not-valid-for-type error

### Requirement: Segments Are Live, Not Frozen Lists

A Segment SHALL be evaluated dynamically at blast-send time, not
materialized as a static contact list at save time. New Contacts matching
the rules SHALL be auto-included in future Blasts.

#### Scenario: New contact auto-included in next blast

- **GIVEN** a Segment with rule `industry = "gemeente"` saved at 2026-01-01
- **WHEN** a new Contact with `industry = "gemeente"` is created on 2026-02-15 and a Blast targeting that Segment is sent on 2026-02-16
- **THEN** `SegmentService.getMembersForBlast()` SHALL include the new Contact
- **AND** the segment query SHALL NOT have been materialized as a static list

#### Scenario: Contact deletion removes from future blasts

- **GIVEN** a Contact in an active Segment with rule `industry = "gemeente"`
- **WHEN** the Contact is deleted
- **THEN** the Contact SHALL NOT appear in the next member projection
- **AND** `SegmentService.estimateSize()` SHALL reflect the deletion
