# Appointment Booking — Skill-Routing Eligibility (Member 03) Delta Spec

## Purpose

Query skill-routing to determine which Resources are eligible for a Service, then
intersect with availability so unqualified resources never surface.

---

## ADDED Requirements

### Requirement: REQ-APT-004 Skill-Based Routing

The system MUST query skill-routing to determine which Resources are eligible for a
Service, then intersect with availability.

**Feature tier**: V1

#### Scenario: Service requires skill, only eligible resources shown

- **GIVEN** a Service requires `requiredSkills: ["color-certified"]` and the tenant has three stylists (two with that skill, one without)
- **WHEN** eligible resources are computed for that Service
- **THEN** the result MUST only include the two certified stylists, never the uncertified one

#### Scenario: Resource with no skills is eligible for no-skill services

- **GIVEN** a Service has empty `requiredSkills` and a Resource has no skills
- **WHEN** eligibility is computed
- **THEN** the Resource MUST be eligible

#### Scenario: Multi-step service with varying skill requirements

- **GIVEN** a Service has `multiStep: [{step 1, color-certified}, {step 2, gap}, {step 3, any-stylist}]`
- **WHEN** eligibility is computed per step
- **THEN** step 1 MUST use only color-certified resources, the gap step MUST require no resource, and step 3 MUST accept any stylist
