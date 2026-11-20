# time-entry-mobile Specification (delta)

## MODIFIED Requirements

### Requirement: REQ-002 — PWA shell and offline buffering are the only mobile-specific code

The mobile-specific layer SHALL consist of a PWA shell, an offline buffer and a sync queue; it SHALL NOT contain a parallel timer engine or capture data model. The offline buffer and sync queue SHALL be the generic nextcloud-vue composable `useOfflineQueue` (IndexedDB-backed, one idempotency key per pending write, flushed on the `online` event), so any app can reuse it; pipelinq SHALL NOT keep a time-specific copy. The mobile timer page SHALL show how many entries are waiting to be sent while offline.

#### Scenario: Offline buffer + sync queue wrap the leaf capture action
- **GIVEN** the mobile timer page
- **WHEN** the user records time offline
- **THEN** `useOfflineQueue` SHALL keep each capture in IndexedDB with an idempotency key
- **AND** SHALL flush them to the leaf on the `online` event
- **AND** the timer state/duration semantics SHALL match the leaf's, not a pipelinq-local definition.

#### Scenario: Pending entries are visible
- **GIVEN** the user recorded three entries without a connection
- **WHEN** they look at the mobile timer page
- **THEN** it says "3 entries waiting to be sent"
- **AND** after the connection returns and the queue is flushed it no longer shows the line
