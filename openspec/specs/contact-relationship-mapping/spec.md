# contact-relationship-mapping Specification

## Purpose
Model bidirectional typed relationships between contacts (parent/child, partner, colleague, employer/employee). Auto-create inverse relationships. For government: family relationships for social domain, company structures for permits, organizational hierarchies.

## Context
Understanding how contacts relate to each other is essential for government service delivery. In the social domain, family relationships determine eligibility for benefits. For permits, company ownership structures affect liability. For CRM, knowing who works with whom improves relationship management. This spec adds a relationship layer on top of existing contact entities.

## ADDED Requirements

### Requirement: Relationship entity
The system MUST provide a Relationship entity connecting two contacts with a typed, bidirectional link.

#### Scenario: Create a relationship
- GIVEN contacts "Jan Bakker" and "Maria Bakker"
- WHEN the user creates a relationship of type "partner" between them
- THEN a relationship record MUST be created from Jan to Maria with type "partner"
- AND an inverse relationship MUST be automatically created from Maria to Jan with type "partner"
- AND both contacts' detail views MUST show the relationship

#### Scenario: Parent-child relationship with inverse
- GIVEN contacts "Pieter de Vries" (parent) and "Sophie de Vries" (child)
- WHEN the user creates a relationship of type "ouder" from Pieter to Sophie
- THEN the inverse relationship "kind" MUST be automatically created from Sophie to Pieter
- AND Pieter's detail view MUST show "Sophie de Vries -- kind"
- AND Sophie's detail view MUST show "Pieter de Vries -- ouder"

#### Scenario: Employer-employee relationship
- GIVEN contact (organization) "Gemeente Utrecht" and contact (person) "Jan Bakker"
- WHEN the user creates relationship "werkgever" from Gemeente Utrecht to Jan
- THEN the inverse "werknemer" MUST be created from Jan to Gemeente Utrecht
- AND Jan's detail view MUST show his employer

### Requirement: Relationship types
The system MUST provide configurable relationship types with defined inverse labels.

#### Scenario: Default relationship types
- GIVEN the system is freshly installed
- THEN the following relationship types MUST be available by default:

| Type | Inverse | Category |
|------|---------|----------|
| partner | partner | Familie |
| ouder | kind | Familie |
| kind | ouder | Familie |
| broer/zus | broer/zus | Familie |
| werkgever | werknemer | Professioneel |
| werknemer | werkgever | Professioneel |
| collega | collega | Professioneel |
| contactpersoon | organisatie | Professioneel |
| moederorganisatie | dochterorganisatie | Organisatie |
| dochterorganisatie | moederorganisatie | Organisatie |

#### Scenario: Custom relationship types
- GIVEN a Pipelinq admin
- WHEN they create a new relationship type "mentor" with inverse "mentee" in category "Professioneel"
- THEN the type MUST be available when creating relationships
- AND both the type and its inverse MUST appear in the type picker

### Requirement: Relationship management on contact detail
The contact detail view MUST display and manage relationships.

#### Scenario: View relationships on contact detail
- GIVEN contact "Jan Bakker" with relationships: partner (Maria), werkgever (Gemeente Utrecht), collega (Pieter)
- WHEN the user views Jan's detail page
- THEN a "Relaties" section MUST display all relationships grouped by category
- AND each relationship MUST show: contact name, relationship type, and a link to the related contact

#### Scenario: Add relationship from detail view
- GIVEN the contact detail view for "Jan Bakker"
- WHEN the user clicks "Relatie toevoegen"
- THEN a dialog MUST appear with: contact search, relationship type selector
- AND selecting a contact and type MUST create both the relationship and its inverse

#### Scenario: Remove relationship
- GIVEN a relationship between Jan and Maria
- WHEN the user removes the relationship from Jan's detail view
- THEN both the relationship AND its inverse MUST be deleted
- AND Maria's detail view MUST no longer show the relationship to Jan

### Requirement: Relationship search and filtering
The system MUST support searching contacts by their relationships.

#### Scenario: Find all employees of an organization
- GIVEN organization "Gemeente Utrecht" with 5 employee relationships
- WHEN the user searches for contacts with relationship "werknemer" of "Gemeente Utrecht"
- THEN all 5 employees MUST be returned

#### Scenario: Filter contacts by relationship existence
- GIVEN a contact list
- WHEN the user filters by "heeft relatie: werkgever"
- THEN only contacts with an active employer relationship MUST be shown

### Requirement: Relationship data model
Relationships MUST be stored as OpenRegister objects.

#### Scenario: Relationship object structure
- GIVEN a relationship between two contacts
- THEN the object MUST store:
  - `fromContact`: UUID reference to the source contact
  - `toContact`: UUID reference to the target contact
  - `type`: relationship type identifier
  - `inverseType`: the inverse relationship type identifier
  - `notes`: optional free text
  - `startDate`: optional date when relationship started
  - `endDate`: optional date when relationship ended

## Dependencies
- Pipelinq contact entities (OpenRegister)
- Contact detail view (for relationship section integration)
- OpenRegister for relationship object storage

---

### Current Implementation Status

**NOT implemented.** No relationship entity, relationship types, or relationship management UI exists in the codebase.

- No `relationship` schema in `lib/Settings/pipelinq_register.json` -- the register only defines: client, contact, lead, request, pipeline, product, productCategory, leadProduct.
- No relationship-related controllers, services, or Vue components.
- No relationship section on contact or client detail views.
- The existing contact-to-client link (`contact.client` UUID reference) represents a simple parent link (contact works for client), not a typed bidirectional relationship system.
- No inverse relationship auto-creation logic.
- No configurable relationship types or admin UI for managing them.

**Mock Registers (dependency):** This spec depends on mock BRP registers being available in OpenRegister for development and testing of family relationship features. These registers are available as JSON files that can be loaded on demand from `openregister/lib/Settings/`. Production deployments should connect to the actual Haal Centraal BRP API via OpenConnector.

### Using Mock Register Data

This spec depends on the **BRP** mock register for family relationship data (partners, kinderen, ouders).

**Loading the register:**
```bash
# Load BRP register (35 persons with family relationships, register slug: "brp", schema: "ingeschreven-persoon")
docker exec -u www-data nextcloud php occ openregister:load-register /var/www/html/custom_apps/openregister/lib/Settings/brp_register.json
```

**Test data for this spec's use cases:**
- **Partner relationship**: BSN `999995376` (Brigitte Moulin) has partner Jean Roussaex -- test partner auto-linking
- **Parent-child relationships**: BSN `999990627` (Stephan Janssen) is father of BSN `999997580` and BSN `999995145` -- test ouder/kind bidirectional relationships
- **Family unit**: BSN `999992570` (Albert Vogel) has partner, child, and 2 parents -- test complete family network display
- **Employer-employee**: Use KVK `69599084` (Test EMZ Dagobert) with any person contact -- test werkgever/werknemer relationship

**Querying family data:**
```bash
# Find person with family references
curl "http://localhost:8080/index.php/apps/openregister/api/objects/{brp_register_id}/{person_schema_id}?_search=999990627" -u admin:admin
# Response includes: partners[], ouders[], kinderen[] arrays with BSN cross-references
```

### Standards & References
- VNG Klantinteracties -- defines relationship concepts between `Partij` entities (`PartijRelatie`)
- Schema.org `Person.knows`, `Person.relatedTo`, `Organization.member` -- relevant relationship predicates
- Common Ground -- relationship modeling between subjects (personen/organisaties)
- Haal Centraal BRP API -- family relationship data (partner, kinderen, ouders) can be retrieved from BRP

### Specificity Assessment
- The spec is reasonably specific for a first implementation -- relationship entity structure, default types with inverses, and UI scenarios are well-defined.
- **Missing**: No API contract for relationship CRUD endpoints.
- **Missing**: No specification of how relationship search/filtering integrates with OpenRegister's query API (e.g., filtering by `fromContact` or `toContact`).
- **Missing**: No specification of cascade behavior -- what happens to relationships when a contact is deleted?
- **Missing**: No specification of permissions -- can any user create/delete relationships, or are there role restrictions?
- **Open question**: Should the `Relationship` be a new OpenRegister schema in the pipelinq register, or a generic OpenRegister feature (relationships between any objects)?
- **Open question**: How should inverse auto-creation handle failures? (e.g., if creating the inverse fails, should the primary be rolled back?)
