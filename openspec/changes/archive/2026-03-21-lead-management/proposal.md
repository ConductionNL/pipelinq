# Lead Management Specification

## Problem
Lead management handles sales opportunities -- from first contact through to won or lost. A lead is a unified entity (no separate Opportunity split) that flows through configurable pipeline stages. Pipeline stages encode qualification level, making a separate conversion step unnecessary.
**Standards**: Schema.org (`Demand`), Industry CRM consensus (HubSpot, Salesforce, EspoCRM)
**Primary feature tier**: MVP (with V1 and Enterprise enhancements noted per requirement)

## Proposed Solution
Implement Lead Management Specification following the detailed specification. Key requirements include:
- Requirement: Lead Capture from External Sources [V1]
- Requirement: Lead Qualification Scoring [V1]
- Requirement: Lead-to-Client Conversion [V1]
- Requirement: Lead Assignment Rules [V1]
- Requirement: Lead Deduplication [V1]

## Scope
This change covers all requirements defined in the lead-management specification.

## Success Criteria
#### Scenario 1: Create a minimal lead
#### Scenario 2: Create a lead with full sales fields
#### Scenario 3: Create a lead linked to client and contact
#### Scenario 4: Update a lead
#### Scenario 5: Delete a lead
