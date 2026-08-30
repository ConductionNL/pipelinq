# Spec delta — time-entry-mobile (offline capture feeds the time-tracker leaf)

## ADDED Requirements

### Requirement: REQ-001 — Mobile capture persists to the time-tracker leaf, not a pipelinq schema

The mobile timer SHALL persist captured hours to the OpenRegister time-tracker
leaf's capture endpoints; Pipelinq SHALL NOT introduce or extend a `timeEntry`
schema for the mobile path (hydra ADR-022).

#### Scenario: Buffered entries submit to the leaf on reconnect

- **GIVEN** a user starts/pauses/stops the timer on a mobile browser with no
  network
- **WHEN** the device reconnects
- **THEN** the buffered captures SHALL be submitted to the time-tracker leaf via
  the OR integration link endpoints
- **AND** no pipelinq-owned `timeEntry` object SHALL be created
- **AND** submission SHALL be idempotent (a buffered entry already accepted by
  the leaf is not duplicated).

### Requirement: REQ-002 — PWA shell and offline buffering are the only mobile-specific code

The mobile-specific layer SHALL consist of a PWA shell, an IndexedDB offline
buffer, and a sync queue; it SHALL NOT contain a parallel timer engine or
capture data model.

#### Scenario: Offline buffer + sync queue wrap the leaf capture action

- **GIVEN** the mobile timer view `TimerMobile.vue`
- **WHEN** the user records time offline
- **THEN** `useOfflineTimer` SHALL buffer the events in IndexedDB
- **AND** `useSyncQueue` SHALL flush them to the leaf on the `online` event
- **AND** the timer state/duration semantics SHALL match the leaf's, not a
  pipelinq-local definition.

### Requirement: REQ-003 — Mobile view meets touch + responsive targets

The mobile timer view SHALL render without horizontal scrolling at 375 px and
768 px and provide touch targets of at least 48×48 px.

#### Scenario: Responsive, installable mobile timer

- **GIVEN** the PWA manifest and `TimerMobile.vue`
- **WHEN** loaded on a mobile viewport ≤768 px or in `standalone` display mode
- **THEN** the view SHALL render full-viewport with ≥48×48 px Start/Pause/Stop
  targets and an offline status banner
- **AND** the PWA manifest SHALL allow "Add to Home Screen" on Android/iOS.

### Requirement: REQ-004 — Optional GPS is leaf metadata, not a pipelinq schema field

When GPS is granted, the captured location SHALL be attached to the leaf capture
as metadata; Pipelinq SHALL NOT add a schema field for it.

#### Scenario: GPS attaches to the leaf capture

- **GIVEN** the user has granted location permission and GPS capture is enabled
- **WHEN** the timer starts
- **THEN** latitude/longitude SHALL be attached to the leaf capture's
  description/metadata payload
- **AND** no pipelinq schema extension SHALL be introduced to store it.
