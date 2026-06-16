# Marketing Segmentation and Blast — Segment and Blast Views

## ADDED Requirements

### Requirement: Segment Builder UI Composes Rule Trees

The SegmentBuilder Vue component SHALL allow marketers to construct rule
trees visually using AND/OR logic with leaf predicates, validate them, and
show a live size estimate before commit.

#### Scenario: Visual rule tree with live validation

- **GIVEN** a marketer opens SegmentBuilder for entityType "contact"
- **WHEN** they add a predicate with an invalid operator for the field type
- **THEN** the component SHALL display a field-level error and disable save until resolved

#### Scenario: Live size estimate shown

- **GIVEN** a valid rule tree
- **WHEN** the rules change
- **THEN** the component SHALL show "Estimated members: N" from a debounced backend call

### Requirement: Blast Creation Wizard Gates on Compliance

The BlastForm Vue component SHALL walk the marketer through name → segment →
template → channel → schedule → A/B and SHALL check compliance before send.

#### Scenario: Missing-consent modal on send

- **GIVEN** a segment with contacts lacking email consent
- **WHEN** the marketer attempts to send
- **THEN** the form SHALL show a modal listing missing contacts with options "Skip and send", "Request consent", "Cancel"

#### Scenario: Email template validated before save

- **GIVEN** an email channel blast
- **WHEN** the selected template is checked
- **THEN** the form SHALL call the template validation endpoint and surface errors for missing unsubscribe token or address

### Requirement: Live Send Monitor

The BlastMonitor Vue component SHALL show real-time send progress with live
counts and an event timeline.

#### Scenario: Progress bar and totals update by polling

- **GIVEN** a Blast mid-send
- **WHEN** BlastMonitor is open
- **THEN** it SHALL poll `GET /api/blasts/:id` every 2 seconds, update the progress bar and totals grid, prepend new events to the timeline, and stop polling when status is "sent" or "failed"

#### Scenario: Cancel a sending blast

- **GIVEN** a Blast with status "sending"
- **WHEN** the marketer clicks "Cancel send"
- **THEN** the component SHALL POST `/api/blasts/:id/cancel` and show a cancelling state
