# Example data: audit fixes

Delta over `specs/first-time-setup` (REQ-SETUP-PIP-008).

## ADDED Requirements

### Requirement: Every example record imports

When the example records are loaded, every user field (`format: user`, `format: username` or a nextcloud-user reference) SHALL name an account that exists on the server. A value that is a real account SHALL stay. Any other value SHALL become the user who loads the examples, or SHALL be left out when nobody is signed in (the occ path). No example record SHALL be skipped for a user that does not exist.

#### Scenario: An administrator loads the examples

- GIVEN a server whose only account is `admin`
- WHEN `admin` loads the example data from the setup wizard
- THEN every user field of every example record SHALL be `admin` or empty
- AND OpenRegister SHALL skip no record for a `format: user` mismatch

@e2e exclude asserted against the real descriptor and the merged schemas in tests/Unit/Service/Demo/DemoRegisterImporterTest.php.
