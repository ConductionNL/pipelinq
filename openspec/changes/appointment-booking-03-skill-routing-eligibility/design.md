# Design: Appointment Booking — Skill-Routing Eligibility (Member 03)

## Overview

Add skill-based resource eligibility. `getEligibleResources(serviceId)` queries
`skill-routing` (`OCA\SkillRouting\Service\SkillMatchService`) for resources whose
skills satisfy the Service's `requiredSkills`, then the caller intersects with
member 02's availability.

## Backend

Lives on the booking domain seam (a small `EligibilityService` or a method on the
availability/booking boundary, finalised in member 04 where BookingService
consumes it). For this member the eligibility query + intersection is the unit
under test.

**Dependencies:** `ObjectService` (load Service/Resource), `SkillMatchService`
(skill-routing). No skill logic is reimplemented (ADR-012).

**Behaviour:**
- Resource with no skills is eligible for Services with no skill requirements.
- Multi-step services: each step's `skillRequired` filters independently; gap steps
  (`allowGap: true`) require no resource.

## Security (ADR-005)

Read-only. ServiceId is validated against ObjectService scoping before querying
skill-routing; no raw error message leakage.

## Tests

Unit tests mock `SkillMatchService` and `ObjectService`: skill match, no-skill
fallback, multi-step step-specific skills.
