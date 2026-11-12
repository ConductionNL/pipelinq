# dashboard Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `clients-search`.

## ADDED Requirements

### Requirement: A client can be found by name, email or phone outside the client list

The system SHALL offer a find-client search outside the client list that matches a client on name, email address or phone number.

#### Scenario: Finding a caller by phone number from the dashboard

- GIVEN a client whose phone number is +31 6 12345678
- WHEN a user types 0612345678 in the find client widget on the dashboard
- THEN that client is listed
- AND choosing it opens the client page
