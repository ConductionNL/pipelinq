# Tasks: Appointment Booking — Skill-Routing Eligibility (Member 03)

## Section 1: Eligibility

- [x] Implement `getEligibleResources(serviceId)` — query skill-routing for resources matching service.requiredSkills
- [x] Return resource list filtered to only eligible ones
- [x] Handle: resource has no skills (eligible for services with no skill requirements)
- [x] Handle: multi-step services with step-specific skills
- [x] Intersect eligibility with member-02 availability so only bookable, qualified resources surface
- [x] Reuse `skill-routing` SkillMatchService — no skill logic duplicated (ADR-012)
- [x] Add `@spec ...#req-apt-004` PHPDoc

## Section 2: Unit Tests

- [x] Test eligible resources excludes uncertified stylist for a skill-required service
- [x] Test resource with no skills is eligible for a no-skill service
- [x] Test multi-step service applies step-specific skill filters
- [x] Mock `SkillMatchService` and `ObjectService`
