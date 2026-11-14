# lead-management: delta for lead-contact-roles

## ADDED Requirements

### Requirement: A lead shows its contacts with their role

The lead overview MUST list the contacts that have a role on the deal, with the role label and the note, decision maker first. A user MUST be able to add a role, which is stored as a relationship from the lead to the contact with category `CRM Rol`.

#### Scenario: the roles on a lead

- GIVEN lead "Woonportaal voor huurders" has M. Hendriks as decision maker and Peter Lam as gatekeeper
- WHEN the lead opens
- THEN "Contacts and their role" lists M. Hendriks with "Decision maker" before Peter Lam with "Gatekeeper"
- AND "Add contact role" stores a relationship with `fromType` lead
