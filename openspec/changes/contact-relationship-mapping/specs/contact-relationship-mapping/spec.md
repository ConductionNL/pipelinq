# Contact Relationship Mapping - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Relationship entity

The system MUST provide a Relationship entity stored as an OpenRegister object in the `pipelinq` register, connecting two contacts/clients with a typed, bidirectional link.

#### Scenario: Create a symmetric relationship
- GIVEN contacts "Jan Bakker" and "Maria Bakker"
- WHEN the user creates a relationship of type "partner" between them
- THEN a relationship record MUST be created from Jan to Maria with type "partner" and inverseType "partner"
- AND an inverse relationship MUST be automatically created from Maria to Jan

#### Scenario: Create an asymmetric relationship with inverse
- GIVEN contacts "Pieter de Vries" (parent) and "Sophie de Vries" (child)
- WHEN the user creates a relationship of type "ouder" from Pieter to Sophie
- THEN the inverse relationship "kind" MUST be automatically created from Sophie to Pieter

#### Scenario: Employer-employee relationship (cross-entity)
- GIVEN client (organization) "Gemeente Utrecht" and contact (person) "Jan Bakker"
- WHEN the user creates relationship "werkgever" from Gemeente Utrecht to Jan
- THEN the inverse "werknemer" MUST be created from Jan to Gemeente Utrecht

### Requirement: Relationship types registry

The system MUST provide predefined relationship types with automatic inverse mapping.

#### Scenario: Predefined relationship types available
- GIVEN the relationship creation form
- THEN the following types MUST be available: partner, ouder/kind, werkgever/werknemer, collega, leidinggevende/ondergeschikte

### Requirement: Relationship display on detail views

Contact and client detail views MUST show all active relationships.

#### Scenario: View relationships on contact detail
- GIVEN contact "Jan Bakker" has 3 relationships
- WHEN the user views Jan's detail page
- THEN a relationships section MUST show all 3 relationships with type, linked entity name, and status
