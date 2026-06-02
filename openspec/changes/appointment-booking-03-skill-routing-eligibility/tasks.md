# Tasks: Appointment Booking — Skill-Routing Eligibility (Member 03)

## Section 1: Eligibility

- [ ] Implement `getEligibleResources(serviceId)` — query skill-routing for resources matching service.requiredSkills
- [ ] Return resource list filtered to only eligible ones
- [ ] Handle: resource has no skills (eligible for services with no skill requirements)
- [ ] Handle: multi-step services with step-specific skills
- [ ] Intersect eligibility with member-02 availability so only bookable, qualified resources surface
- [ ] Reuse `skill-routing` SkillMatchService — no skill logic duplicated (ADR-012)
- [ ] Add `@spec ...#req-apt-004` PHPDoc

## Section 2: Unit Tests

- [ ] Test eligible resources excludes uncertified stylist for a skill-required service
- [ ] Test resource with no skills is eligible for a no-skill service
- [ ] Test multi-step service applies step-specific skill filters
- [ ] Mock `SkillMatchService` and `ObjectService`
