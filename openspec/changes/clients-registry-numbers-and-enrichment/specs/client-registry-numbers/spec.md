# client-registry-numbers Specification (delta)

## Purpose

A company's official numbers live on its client record, each with a check
status, and empty company details can be filled from the KvK register for many
clients at once. From pipelinq matrix rows `clients-identifiers` and
`clients-bulk-enrichment`.

## ADDED Requirements

### Requirement: An organisation client holds its official numbers (REQ-CRN-001)

An organisation client SHALL hold a list of identifiers, each with a scheme
(KvK, RSIN, VAT, DUNS or OIN) and a value. The system SHALL refuse a value that
does not match its scheme's format, including the eleven test for RSIN.

#### Scenario: An account manager adds a KvK number

- GIVEN an account manager on ClientDetail of the organisation Bakkerij de Korenschoof
- WHEN they add identifier KvK 12345678 and save
- THEN the Identifiers section lists KvK 12345678 with status unchecked

#### Scenario: A malformed RSIN is refused

- GIVEN an account manager adding identifier RSIN 123456789 that fails the eleven test
- WHEN they save
- THEN the form shows that the RSIN is not valid
- AND the client keeps its earlier identifiers

### Requirement: A number can be checked against its register (REQ-CRN-002)

The Identifiers section SHALL offer Check on a KvK and a VAT number. A KvK check
SHALL use OpenRegister's KvK leaf. A VAT check SHALL use shillinq's VIES
service when shillinq is installed. The system SHALL record the status, the
time and the source of the check on the identifier.

#### Scenario: A KvK number is found in the register

- GIVEN a client with KvK 12345678 that the KvK register knows
- WHEN the account manager presses Check on it
- THEN the row shows status valid, today's date and source KvK

#### Scenario: VAT cannot be checked without shillinq

- GIVEN an instance without shillinq and a client with VAT DE123456789
- WHEN the account manager presses Check on the VAT number
- THEN the row shows status unchecked with the reason that shillinq is not installed

### Requirement: Empty company details can be filled from KvK in bulk (REQ-CRN-003)

The Clients list SHALL offer Fill in from KvK on selected organisations. It
SHALL run as an OpenRegister bulk job that is previewed before it writes, SHALL
fill only empty fields, SHALL skip a client without a KvK number and say why,
and SHALL be undoable from the Bulk changes page.

#### Scenario: Twenty clients get their missing addresses

- GIVEN twenty selected organisations with KvK numbers, eight of them without an address
- WHEN a user chooses Fill in from KvK and presses Preview
- THEN the dialog says 8 records will change and 12 already have these values
- AND after Confirm the eight clients show the KvK address on ClientDetail

#### Scenario: A client without a KvK number is skipped

- GIVEN a selected organisation with no KvK identifier
- WHEN the fill runs
- THEN that client is listed as skipped with the reason no KvK number
- AND its fields are unchanged
