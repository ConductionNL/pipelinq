# pos-split-tender Specification

## Purpose
Tender types for the POS and paying one sale with several tenders, including reading the tender types from their own register and schema.

## Requirements

### Requirement: The tender type list reads only tender types

The tender type list MUST read the tender type schema of the configured register, whatever an earlier read or write in the same request left behind.

#### Scenario: another schema was read first

@e2e exclude Asserted in tests/Unit/Service/PosTenderServiceTest.php; the pixel check against board PqBeheerKassa covers the drawn list.

- GIVEN the tender types Contant, Pin, Cadeaubon and Op rekening
- AND an earlier call in the request left the application schema as the context
- WHEN the admin opens the POS tab
- THEN the tender type list shows the four tender types and nothing else
