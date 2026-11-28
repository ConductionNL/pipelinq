# client-management Delta

## ADDED Requirements

### Requirement: The duplicate warning is a bordered notice

The new-client dialog SHALL draw its possible-duplicate warning as a bordered warning notice with a warning icon before
its title and the links to open that client or save anyway under it.

#### Scenario: Board PqKlantNieuw

- **WHEN** a user types the e-mail address of an existing client in the new-client dialog
- **THEN** a bordered notice with a warning icon names the existing client
- **AND** offers "Die klant openen" and "Toch opslaan" under that line
