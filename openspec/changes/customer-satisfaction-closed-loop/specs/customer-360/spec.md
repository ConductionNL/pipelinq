# Customer 360 — Satisfaction Panel Delta

**Spec refs**: `customer-360`, `customer-satisfaction` (V1 + closed-loop delta)

## ADDED Requirements

### Requirement: Per-Client Satisfaction Panel

The customer 360 client view MUST include a satisfaction panel showing the client's NPS, average satisfaction rating, response count, trend direction (current vs. previous 90-day window), and the most recent open-text verbatims, aggregated from survey responses linked to the client directly or via their invitation's linked entity. Clients without responses MUST see an explanatory empty state.

**Feature tier**: MVP

#### Scenario: Satisfaction panel for a surveyed client

- GIVEN a client with 6 survey responses across two contactmomenten
- WHEN a user opens the client in customer 360
- THEN the satisfaction panel MUST show the client-level NPS, average rating, response count of 6, a trend indicator, and up to 3 recent verbatims

#### Scenario: Empty state without responses

- GIVEN a client with no linked survey responses
- WHEN the client view is opened
- THEN the satisfaction panel MUST render an empty state explaining that no satisfaction data has been collected yet
