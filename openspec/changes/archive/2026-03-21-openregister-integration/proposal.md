# OpenRegister Integration Specification

## Problem
Pipelinq stores all data as OpenRegister objects -- it owns no database tables. This specification defines how the register and schemas are initialized, how the frontend and backend interact with the OpenRegister API for CRUD operations, how Pinia stores manage state, how schema validation works, how errors are handled, and how cross-entity references, audit trails, RBAC, pagination, and performance concerns are addressed. OpenRegister is the foundational layer for every Pipelinq feature.
**Standards**: OpenAPI 3.0.0 (schema format), OpenRegister API conventions
**Feature tier**: MVP (foundation for all features)

## Proposed Solution
Implement OpenRegister Integration Specification following the detailed specification. Key requirements include:
- Requirement: Register Configuration File
- Requirement: Register Configuration File Format Compliance
- Requirement: Auto-Configuration on Install (Repair Step)
- Requirement: Schema-to-IAppConfig Mapping
- Requirement: Store Registration

## Scope
This change covers all requirements defined in the openregister-integration specification.

## Success Criteria
- Configuration file exists and is valid
- All entity schemas are defined
- Client schema defines Schema.org Person/Organization properties
- Contact schema defines vCard-aligned contact person properties
- Lead schema defines opportunity tracking properties
