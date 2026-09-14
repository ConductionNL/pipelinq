# contactmomenten

## ADDED Requirements

### Requirement: The contact moment panel SHALL show the party's indicators (REQ-CMI-001)

The panel that renders contact moments on a host object SHALL also render the
indicators held by the party those contact moments belong to, resolved live per
REQ-PFI-003. Indicators SHALL be shown before the moments, so a KCC agent taking a
call reads the flag before they speak.

An indicator declaring `requiresAcknowledgement` SHALL be presented so that the
handler confirms they have seen it, and the confirmation SHALL be recorded with the
handler and the time.

This extends the panel specified by `contact-moments-on-pipelinq-schema`, which is a
declared dependency of this change. Its four existing requirements are unchanged.

#### Scenario: An aggression flag is read before the call
- **GIVEN** a party carrying "agressie-registratie" and three contact moments
- **WHEN** the panel renders
- **THEN** the indicator appears above the moments

#### Scenario: An acknowledgement is recorded
- **GIVEN** an indicator declaring `requiresAcknowledgement`
- **WHEN** a handler confirms it
- **THEN** the confirmation is stored with the handler and the time

#### Scenario: A blocked party is said at the point of writing
- **GIVEN** a party carrying an indicator asserting `blocksOutbound`
- **WHEN** a handler starts an outbound contact moment from the panel
- **THEN** the panel reports the block and names the indicator, and the append is
  refused
