# appointment-booking Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `meeting-link`.

## ADDED Requirements

### Requirement: A user can send a booking link that offers the free slots in their own calendar

The system SHALL give a user a shareable booking link whose page offers only the slots that are free in that user's calendar and books the chosen slot into it.

#### Scenario: A busy hour is not offered

- GIVEN a user with a meeting on Tuesday at 11:00
- WHEN the recipient of their booking link opens it
- THEN Tuesday 11:00 is not offered
- AND after the recipient picks Tuesday 10:00, an event at that time with the recipient's name is in the user's calendar
