# Client Management Specification

## Problem
Client management is the core capability of Pipelinq. A client represents a person or organization that the team has a relationship with. Contact persons are individuals linked to organization clients, qualified by role. This specification covers client and contact person CRUD, list views with search/sort/filter, the client detail view (info panel, summary stats, contact persons, leads, requests, activity timeline), validation rules, Nextcloud Contacts sync, and future capabilities such as duplicate detection and import/export.
**Standards**: Schema.org (`Person`, `Organization`, `ContactPoint`), vCard (RFC 6350), VNG Klantinteracties (`Partij`, `Betrokkene`, `DigitaalAdres`)
**Feature tier**: MVP (core), V1 (extended), Enterprise (advanced)

## Proposed Solution
Implement Client Management Specification following the detailed specification. Key requirements include:
- Requirement: Client Creation
- Requirement: Client Update
- Requirement: Client Deletion
- Requirement: Client Validation
- Requirement: Client List View

## Scope
This change covers all requirements defined in the client-management specification.

## Success Criteria
- Create a person client with minimal fields
- Create an organization client with full fields
- Create a client with only required fields
- Fail to create a client without required name
- Fail to create a client without required type
