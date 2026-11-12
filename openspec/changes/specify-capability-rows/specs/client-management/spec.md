# client-management Specification (delta)

## Purpose

Requirements for capability matrix rows whose behaviour no requirement stated yet. Rows: `clients-party-kind`, `clients-opencorporates`.

## ADDED Requirements

### Requirement: A client records what kind of party it is

The system SHALL let a user set the kind of a client from the party kinds the administrator keeps, which include at least citizen and business.

#### Scenario: An organisation is marked as a business

- GIVEN the party kinds citizen and business exist
- WHEN a user edits an organisation client and sets its kind to business
- THEN the client page shows the kind business
- AND the client list can be filtered on that kind

### Requirement: An organisation abroad can be looked up in an international company register

The system SHALL let a user search an international company register by name and country and fill a new organisation client from the chosen result.

#### Scenario: A German company fills the form

- GIVEN a user is creating an organisation client
- WHEN they search the international register for Siemens in Germany and pick a result
- THEN the form holds the registered name, address and registration number from the register
- AND the user can change them before saving
