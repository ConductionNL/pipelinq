# marketing-integrated-campaigns: delta for journey-flow-dates

## ADDED Requirements

### Requirement: A journey records when it became a flow and when it went live

The first clean compile of a journey MUST record `compiledAt`; later compiles MUST keep it. A journey whose status becomes active MUST record `activatedAt`. The Flow card MUST name the day the journey was converted into a flow.

#### Scenario: a journey compiled on 2 October

- GIVEN journey "Welkom nieuwe inwoners" was compiled on 2 October 2026
- WHEN its page opens
- THEN the Flow card reads "Converted into a flow on 2 Oct. The flow runs in the automation engine."
