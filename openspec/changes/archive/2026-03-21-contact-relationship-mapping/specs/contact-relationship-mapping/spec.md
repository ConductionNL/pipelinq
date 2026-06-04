---
status: partial
---

# Contact Relationship Mapping Specification

## Purpose

Model bidirectional typed relationships between contacts (parent/child, partner, colleague, employer/employee). Auto-create inverse relationships. For government: family relationships for social domain, company structures for permits, organizational hierarchies. For CRM: understand decision-making hierarchies, identify influencers and gatekeepers, and map stakeholder networks.

## Context

Understanding how contacts relate to each other is essential for both government service delivery and commercial CRM. In the social domain, family relationships determine eligibility for benefits. For permits, company ownership structures affect liability. In sales, knowing who the decision maker, influencer, and gatekeeper are improves deal progression. This spec adds a relationship layer on top of existing contact entities, with CRM-specific contact roles for deal management.

**Feature tier:** V1 (basic relationships), Enterprise (roles, org charts, relationship scoring)

**Competitor context:** EspoCRM models Account-Contact relationships with a `role` field (Decision Maker, Influencer, Evaluator, etc.) and supports many-to-many linking. Krayin CRM has Organizations -> Persons relationships with job title context. Twenty CRM provides a visual relationship graph for companies and contacts. Monica CRM (personal CRM) has the most sophisticated relationship engine with 50+ relationship types including custom types. This spec combines Monica's relationship type flexibility with EspoCRM's deal-context roles.

---

## Requirements

### Requirement: Relationship entity [V1]
The system MUST provide a Relationship entity stored as an OpenRegister object in the `pipelinq` register, connecting two contacts with a typed, bidirectional link.

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `fromContact` | string (uuid) | YES | UUID reference to the source contact or client |
| `toContact` | string (uuid) | YES | UUID reference to the target contact or client |
| `fromType` | string | no | Entity type of source: "contact" or "client" |
| `toType` | string | no | Entity type of target: "contact" or "client" |
| `type` | string | YES | Relationship type identifier (e.g., "partner", "ouder", "werkgever") |
| `inverseType` | string | YES | The inverse relationship type identifier |
| `notes` | string | no | Optional free text context for this relationship |
| `startDate` | date | no | Date when the relationship started |
| `endDate` | date | no | Date when the relationship ended (null = active) |
| `strength` | string | no | Relationship strength: "strong", "medium", "weak". Default: "medium" |

#### Scenario: Create a symmetric relationship
- GIVEN contacts "Jan Bakker" and "Maria Bakker"
- WHEN the user creates a relationship of type "partner" between them
- THEN a relationship record MUST be created from Jan to Maria with type "partner" and inverseType "partner"
- AND an inverse relationship MUST be automatically created from Maria to Jan with type "partner" and inverseType "partner"
- AND both contacts' detail views MUST show the relationship

#### Scenario: Create an asymmetric relationship with inverse
- GIVEN contacts "Pieter de Vries" (parent) and "Sophie de Vries" (child)
- WHEN the user creates a relationship of type "ouder" from Pieter to Sophie
- THEN the inverse relationship "kind" MUST be automatically created from Sophie to Pieter
- AND Pieter's detail view MUST show "Sophie de Vries -- kind"
- AND Sophie's detail view MUST show "Pieter de Vries -- ouder"

#### Scenario: Employer-employee relationship (cross-entity)
- GIVEN client (organization) "Gemeente Utrecht" and contact (person) "Jan Bakker"
- WHEN the user creates relationship "werkgever" from Gemeente Utrecht to Jan
- THEN the inverse "werknemer" MUST be created from Jan to Gemeente Utrecht
- AND Jan's contact detail view MUST show his employer
- AND Gemeente Utrecht's client detail view MUST show Jan as an employee

#### Scenario: Relationship with date range
- GIVEN contacts "Anna Smit" and "Software BV"
- WHEN the user creates a "werknemer" relationship with startDate "2020-01-15" and endDate "2024-06-30"
- THEN the relationship MUST store both dates
- AND the relationship MUST be displayed as "Former employee (2020-2024)"
- AND ended relationships MUST be visually distinct from active ones

#### Scenario: Prevent duplicate relationships
- GIVEN a relationship already exists from Jan to Maria of type "partner"
- WHEN the user attempts to create another "partner" relationship from Jan to Maria
- THEN the system MUST reject the creation with error: "This relationship already exists"
- AND the user MUST be shown the existing relationship

---

### Requirement: Relationship types [V1]
The system MUST provide configurable relationship types with defined inverse labels, organized by category.

#### Scenario: Default relationship types
- GIVEN the system is freshly installed
- THEN the following relationship types MUST be available by default:

| Type | Inverse | Category | Symmetric |
|------|---------|----------|-----------|
| partner | partner | Familie | yes |
| ouder | kind | Familie | no |
| kind | ouder | Familie | no |
| broer/zus | broer/zus | Familie | yes |
| werkgever | werknemer | Professioneel | no |
| werknemer | werkgever | Professioneel | no |
| collega | collega | Professioneel | yes |
| contactpersoon | organisatie | Professioneel | no |
| moederorganisatie | dochterorganisatie | Organisatie | no |
| dochterorganisatie | moederorganisatie | Organisatie | no |
| beslisser | - | CRM Rol | no |
| beinvloeder | - | CRM Rol | no |
| gatekeeper | - | CRM Rol | no |
| mentor | mentee | Professioneel | no |

#### Scenario: Custom relationship types
- GIVEN a Pipelinq admin
- WHEN they navigate to Settings > Relationship Types
- THEN they MUST be able to create a new relationship type with:
  - Type label (e.g., "adviseur")
  - Inverse label (e.g., "klant")
  - Category (selected from existing or new)
  - Whether it is symmetric (same type in both directions)
- AND both the type and its inverse MUST appear in the type picker

#### Scenario: Edit relationship type
- GIVEN an existing relationship type "mentor/mentee"
- WHEN the admin edits the label to "coach/coachee"
- THEN all existing relationships of this type MUST display the updated labels
- AND the type identifier in the data MUST remain unchanged (label is display-only)

#### Scenario: Delete relationship type
- GIVEN a relationship type with 0 existing relationships
- WHEN the admin deletes the type
- THEN the type MUST be removed from the available types
- AND types with existing relationships MUST NOT be deletable (show warning: "X relationships use this type")

---

### Requirement: Contact roles in deals [V1]
The system MUST support CRM-specific contact roles that indicate a contact's function in a sales deal or organizational decision.

#### Scenario: Assign deal role to contact
- GIVEN a lead "Digital Transformation Gemeente ABC" with contact "Jan Bakker"
- WHEN the user assigns Jan the role "Beslisser" (Decision Maker) on this lead
- THEN the lead detail view MUST show Jan with the "Beslisser" badge
- AND Jan's contact detail view MUST show his role on this lead

#### Scenario: Multiple contacts with different roles
- GIVEN a lead with contacts:
  - Jan Bakker: Beslisser (Decision Maker)
  - Maria Jansen: Beinvloeder (Influencer)
  - Pieter de Vries: Gatekeeper
- WHEN the user views the lead detail
- THEN all three contacts MUST be displayed with their respective role badges
- AND the contacts MUST be sorted by role importance: Beslisser first

#### Scenario: Role-based contact display
- GIVEN the existing contact field on leads stores a single contact UUID
- THEN the system MUST support multiple contacts per lead via a contactRoles array
- AND each entry MUST store: contact UUID, role type, and optional notes
- AND the primary contact (existing `contact` field) MUST be preserved for backward compatibility

#### Scenario: Default CRM roles
- GIVEN the system defaults
- THEN the following CRM roles MUST be available:
  - Beslisser (Decision Maker) -- has final authority
  - Beinvloeder (Influencer) -- influences the decision
  - Gatekeeper -- controls access to decision makers
  - Gebruiker (End User) -- will use the product/service
  - Kampioen (Champion) -- internal advocate
  - Evaluator -- evaluates technical fit

---

### Requirement: Relationship management on contact detail [V1]
The contact and client detail views MUST display and manage relationships.

#### Scenario: View relationships on contact detail
- GIVEN contact "Jan Bakker" with relationships: partner (Maria), werkgever (Gemeente Utrecht), collega (Pieter)
- WHEN the user views Jan's detail page
- THEN a "Relaties" section MUST display all relationships grouped by category (Familie, Professioneel, Organisatie)
- AND each relationship MUST show: contact/client name, relationship type, and a clickable link to the related entity
- AND ended relationships (with endDate in the past) MUST be shown separately or collapsed

#### Scenario: Add relationship from detail view
- GIVEN the contact detail view for "Jan Bakker"
- WHEN the user clicks "Relatie toevoegen"
- THEN a dialog MUST appear with:
  - Entity search (searches both contacts and clients)
  - Relationship type selector (grouped by category)
  - Optional notes field
  - Optional start date
- AND selecting an entity and type MUST create both the relationship and its inverse

#### Scenario: Remove relationship
- GIVEN a relationship between Jan and Maria
- WHEN the user removes the relationship from Jan's detail view
- THEN a confirmation dialog MUST appear: "Remove the relationship between Jan Bakker and Maria Bakker?"
- AND upon confirmation, both the relationship AND its inverse MUST be deleted
- AND Maria's detail view MUST no longer show the relationship to Jan

#### Scenario: Edit relationship
- GIVEN an existing relationship from Jan to Gemeente Utrecht of type "werknemer"
- WHEN the user clicks edit on the relationship
- THEN the user MUST be able to:
  - Change the relationship type
  - Add or update notes
  - Set a start or end date
  - Change the relationship strength
- AND the inverse relationship MUST update accordingly

#### Scenario: Relationship section on client detail
- GIVEN a client "Gemeente Utrecht" with employee relationships to 5 contacts
- WHEN the user views the client detail page
- THEN a "Relaties" section MUST display all relationships
- AND organization-specific relationships (moederorganisatie, dochterorganisatie) MUST be shown first

---

### Requirement: Organizational hierarchy visualization [Enterprise]
The system MUST provide visual representation of organizational structures through relationship data.

#### Scenario: Organization chart from relationships
- GIVEN client "Gemeente Utrecht" with relationships:
  - Is dochterorganisatie of "Provincie Utrecht"
  - Has 3 contact employees (directeur, manager, medewerker)
- WHEN the user views Gemeente Utrecht's org chart
- THEN a hierarchical visualization MUST display:
  - Parent organization at the top
  - Current organization
  - Key contacts with their roles
- AND each node MUST be clickable to navigate to the entity detail

#### Scenario: Stakeholder map for a lead
- GIVEN a lead with 5 contacts assigned with different CRM roles
- WHEN the user views the lead's stakeholder map
- THEN a visual diagram MUST show the contacts arranged by role importance
- AND relationships between the contacts themselves MUST be shown as connecting lines
- AND the diagram MUST help the sales rep understand the decision-making network

#### Scenario: Simple relationship list fallback
- GIVEN a device with limited screen width or a user preference for list view
- WHEN the org chart or stakeholder map is requested
- THEN the system MUST provide a flat list alternative
- AND the list MUST show the same information in tabular form

---

### Requirement: Relationship search and filtering [V1]
The system MUST support searching contacts by their relationships.

#### Scenario: Find all employees of an organization
- GIVEN client "Gemeente Utrecht" with 5 employee relationships
- WHEN the user searches for contacts with relationship "werknemer" of "Gemeente Utrecht"
- THEN all 5 employees MUST be returned
- AND the search MUST use OpenRegister's query API to filter by `toContact` and `type`

#### Scenario: Filter contacts by relationship existence
- GIVEN a contact list
- WHEN the user filters by "heeft relatie: werkgever"
- THEN only contacts with an active employer relationship MUST be shown
- AND contacts with ended employer relationships MUST be excluded

#### Scenario: Search relationship network
- GIVEN Jan has a partner Maria, who has a werkgever "Software BV"
- WHEN the user views Jan's relationship network (depth 2)
- THEN the system SHOULD show Jan's direct relationships AND their relationships
- AND the network MUST be bounded to prevent performance issues (max depth: 2)

#### Scenario: Find contacts without relationships
- GIVEN the contact list
- WHEN the user filters by "no relationships"
- THEN only contacts with zero relationship objects MUST be shown
- AND this helps identify contacts that need relationship enrichment

---

### Requirement: Relationship data model and storage [V1]
Relationships MUST be stored as OpenRegister objects with proper schema definition.

#### Scenario: Relationship schema in register
- GIVEN the pipelinq_register.json configuration
- THEN a `relationship` schema MUST be defined with all properties from the Relationship entity table
- AND the schema MUST be registered alongside existing schemas (client, contact, lead, etc.)
- AND the schema MUST use `schema:Person.knows` or `schema:Relationship` type annotation

#### Scenario: Inverse auto-creation on save
- GIVEN a relationship is created via the API or UI
- WHEN the relationship is saved to OpenRegister
- THEN the system MUST automatically create the inverse relationship in the same transaction
- AND if the inverse creation fails, the primary MUST also be rolled back (atomic operation)

#### Scenario: Cascade deletion behavior
- GIVEN a contact "Jan Bakker" has 3 relationships
- WHEN Jan is deleted from the system
- THEN all relationships where Jan is `fromContact` or `toContact` MUST be deleted
- AND all inverse relationships MUST also be deleted
- AND no orphan relationship objects MUST remain

#### Scenario: Relationship to Nextcloud Contacts
- GIVEN a contact is synced with Nextcloud Contacts (via `ContactSyncService`)
- WHEN the contact has relationships in Pipelinq
- THEN the RELATED vCard property (RFC 6350 Section 6.6.6) SHOULD be populated with relationship references
- AND synced contacts SHOULD show relationship context in the Nextcloud Contacts app

---

### Requirement: Relationship strength tracking [Enterprise]
The system MUST support tracking the strength and quality of relationships for CRM intelligence.

#### Scenario: Relationship strength levels
- GIVEN a relationship between Jan and Maria
- WHEN the user sets the relationship strength
- THEN the following levels MUST be available: "strong", "medium", "weak"
- AND the default MUST be "medium"
- AND the strength MUST be visually indicated (e.g., thick/thin/dashed connecting lines)

#### Scenario: Activity-based strength inference
- GIVEN a contact has frequent interactions (notes, meetings, emails) with a client
- WHEN the system analyzes interaction patterns
- THEN the relationship strength SHOULD be automatically suggested based on:
  - Number of interactions in the last 90 days
  - Recency of last interaction
  - Variety of interaction types (notes, calls, meetings)
- AND the user MUST be able to override the suggestion

#### Scenario: Relationship health dashboard
- GIVEN a client with multiple contact relationships
- WHEN the user views the client detail
- THEN a "Relationship Health" indicator SHOULD display:
  - Number of active relationships
  - Average relationship strength
  - Time since last interaction with any contact
- AND weak/stale relationships SHOULD be flagged for attention

---

### Requirement: BRP integration for family relationships [Enterprise]
The system MUST support importing family relationship data from the Dutch BRP (Basisregistratie Personen) via OpenConnector.

#### Scenario: Import family relationships from BRP
- GIVEN a contact with BSN (Burgerservicenummer)
- WHEN the user clicks "BRP gegevens ophalen" or a sync runs via OpenConnector
- THEN the system MUST create relationship objects for:
  - Partners (from BRP partner data)
  - Children (from BRP kinderen data)
  - Parents (from BRP ouders data)
- AND each relationship MUST use the correct type/inverse pair (partner/partner, ouder/kind)

#### Scenario: BRP mock data for development
- GIVEN the development environment with BRP mock register loaded
- WHEN testing family relationships
- THEN the following test data MUST be usable:
  - BSN `999995376` (Brigitte Moulin) -- has partner Jean Roussaex
  - BSN `999990627` (Stephan Janssen) -- father of two children
  - BSN `999992570` (Albert Vogel) -- has partner, child, and 2 parents (complete family unit)
- AND the import MUST handle BSN cross-references to create proper bidirectional relationships

#### Scenario: BRP relationship conflict resolution
- GIVEN a manually created relationship between two contacts
- WHEN BRP data is imported for one of those contacts
- THEN the system MUST detect the existing relationship
- AND the system MUST NOT create a duplicate
- AND the system SHOULD update the existing relationship with BRP metadata (dates, type confirmation)

---

### Requirement: Relationship permissions and privacy [Enterprise]
The system MUST enforce appropriate access controls for relationship data, especially for family relationships from government sources.

#### Scenario: Family relationship visibility
- GIVEN family relationships imported from BRP
- WHEN a user views a contact's relationships
- THEN family relationships MUST be visible to the contact's assignee and Pipelinq admins
- AND visibility rules SHOULD be configurable: "all users", "assignee and admins only", "admins only"

#### Scenario: Relationship data in API responses
- GIVEN the OpenRegister API returns relationship objects
- WHEN a user queries relationships they are not authorized to view
- THEN the API MUST exclude unauthorized relationships from the response
- AND the relationship count SHOULD still reflect the total (showing "3 of 5 relationships visible")

#### Scenario: Audit log for relationship access
- GIVEN family relationship data from government sources
- WHEN any user views or exports relationship data
- THEN the access MUST be logged in the activity stream
- AND the audit log MUST comply with AVG/GDPR access logging requirements

---

## Dependencies
- Pipelinq contact entities (OpenRegister)
- Pipelinq client entities (OpenRegister)
- Contact detail view (`src/views/contacts/ContactDetail.vue`) for relationship section
- Client detail view (`src/views/clients/ClientDetail.vue`) for relationship section
- Lead detail view (`src/views/leads/LeadDetail.vue`) for contact roles
- OpenRegister for relationship object storage
- ContactSyncService for Nextcloud Contacts vCard sync
- OpenConnector for BRP integration (optional, Enterprise)
- BRP mock register for development testing

---

### Current Implementation Status

**NOT implemented.** No relationship entity, relationship types, or relationship management UI exists in the codebase.

- No `relationship` schema in `lib/Settings/pipelinq_register.json` -- the register defines: client, contact, lead, request, pipeline, product, productCategory, leadProduct.
- No relationship-related controllers, services, or Vue components.
- No relationship section on `ContactDetail.vue` or `ClientDetail.vue`.
- The existing contact-to-client link (`contact.client` UUID reference) represents a simple parent link (contact works for client), not a typed bidirectional relationship system.
- No inverse relationship auto-creation logic.
- No configurable relationship types or admin UI for managing them.
- No contact roles on leads (the `contact` field on leads is a single UUID, not a roles array).
- No organizational hierarchy visualization.
- No relationship strength tracking.
- No BRP integration for family relationships.

**Foundation components available:**
- `ContactDetail.vue` and `ClientDetail.vue` provide detail page structures with `CnDetailCard` sections where a "Relaties" section can be added.
- `ContactSyncService.php` handles vCard synchronization and could be extended for RELATED property support.
- `ContactVcardPropertyBuilder.php` builds vCard properties and could map relationship data to RFC 6350 RELATED property.
- The `useObjectStore` from `src/store/modules/object.js` can be used for CRUD operations on a new `relationship` schema.
- `ObjectEventListener.php` can detect relationship creation/deletion to trigger inverse management.

**Mock Registers (dependency):** BRP mock register available at `openregister/lib/Settings/brp_register.json` with 35 persons including family relationships (partners, kinderen, ouders).

### Standards & References
- VNG Klantinteracties -- defines `PartijRelatie` for relationships between `Partij` entities.
- Schema.org: `Person.knows`, `Person.relatedTo`, `Organization.member` -- relevant relationship predicates.
- vCard RFC 6350 Section 6.6.6 -- RELATED property with TYPE parameter for relationship categorization.
- Common Ground -- relationship modeling between subjects (personen/organisaties).
- Haal Centraal BRP API -- family relationship data (partner, kinderen, ouders).
- Monica CRM -- Reference implementation for comprehensive relationship type management (50+ types).
- EspoCRM -- Reference for Account-Contact role assignments (Decision Maker, Influencer, etc.).

### Specificity Assessment
- The spec is comprehensive with 10 requirements covering entity model, type management, CRM roles, visualization, search, storage, strength tracking, BRP integration, and privacy.
- **Major implementation effort:** Requires new schema, new UI components, inverse auto-creation logic, and optionally org chart visualization.
- **Key design decisions resolved:**
  - Relationships support both contact-to-contact and client-to-contact links (via fromType/toType fields).
  - Inverse auto-creation is atomic (rollback on failure).
  - CRM roles are separate from family/professional relationships (roles are deal-specific, relationships are entity-level).
  - Relationship types are data-driven (stored as configuration), not hardcoded.
- **Open question:** Should the `Relationship` schema live in the pipelinq register or be a shared OpenRegister feature? Current spec puts it in pipelinq for simplicity.
- **Open question:** How should the org chart be implemented -- client-side JS library (e.g., vis.js) or a simpler CSS-based tree?
- **Open question:** Should BRP relationship import be real-time (on contact view) or batch (cron job)?
