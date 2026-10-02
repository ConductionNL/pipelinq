# lead-rotation Specification (delta)

## Purpose

New leads are assigned automatically and fairly, with a cap and a pause per
person. From pipelinq matrix row `pipeline-lead-rotation`.

## ADDED Requirements

### Requirement: New unassigned leads are assigned in turn (REQ-LRT-001)

When automatic lead assignment is switched on, the system SHALL assign a newly
created lead without an assignee to the eligible colleague with the fewest leads
assigned in the last 30 days. A colleague is eligible when their skill covers the
lead's category, they are available, they have not paused lead assignment and
they are under their 30-day cap. The lead SHALL record why it was assigned.

#### Scenario: Three website leads are shared by two colleagues

- GIVEN automatic assignment is on and Anna and Bram both have skill Solar and no leads this month
- WHEN three Solar leads arrive through the website form
- THEN Anna and Bram have received leads in turn, two and one
- AND each lead shows on LeadDetail why it went to that person

#### Scenario: A capped colleague is passed over

- GIVEN Anna has reached her cap of 10 leads in 30 days and Bram has 4
- WHEN a new Solar lead arrives
- THEN it is assigned to Bram

#### Scenario: A lead made with an assignee is left alone

- GIVEN a sales rep creates a lead and assigns it to themselves
- WHEN the lead is saved
- THEN its assignee is still that sales rep

### Requirement: A colleague can pause their share (REQ-LRT-002)

The agent profile SHALL offer Pause lead assignment. A paused colleague SHALL
receive no leads through rotation. When nobody is eligible, the lead SHALL stay
unassigned with the reason shown.

#### Scenario: Everyone with the skill is on leave

- GIVEN every colleague with skill Solar has paused lead assignment
- WHEN a Solar lead arrives
- THEN the lead stays unassigned
- AND LeadDetail says rotation found no eligible colleague and shows the suggestion list
