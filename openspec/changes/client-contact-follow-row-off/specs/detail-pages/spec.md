# detail-pages Delta: client-contact-follow-row-off

## ADDED Requirements

### Requirement: Simple client and contact pages draw no follow row

In the simple profile the client page (a person or an organisation) and the contact person page SHALL draw no follow
row under the page header. The full profile SHALL keep the follow row on those pages.

#### Scenario: Board PqKlant

- **WHEN** the agent opens the client "Sanne de Vries" in the simple profile
- **THEN** the header ends in its breadcrumb line, without "Volgen"
- **AND** the warning card starts directly under the header

#### Scenario: Board PqContactpersoon

- **WHEN** the agent opens a contact person in the simple profile
- **THEN** the header ends in its breadcrumb line, without "Volgen"

#### Scenario: Full profile keeps following

- **WHEN** an administrator sets the full structure profile
- **THEN** the client and contact person pages draw the follow row as before
