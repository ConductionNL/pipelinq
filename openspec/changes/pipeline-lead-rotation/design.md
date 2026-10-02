# Design: pipeline-lead-rotation

## Context (read at pipelinq development cfe0a0a51)

- **Suggestion.** `lib/Service/RoutingService.php::getSuggestedAgents()` (:92)
  finds agent profiles whose skills cover the lead `category`
  (`findMatchingAgents`, :259, via `skill.categories`), drops unavailable
  profiles (`filterByAvailability`, :141) and those at `maxConcurrent`
  (`filterByCapacity`, :142), and ranks by open workload
  (`getAgentWorkload`, :188). LeadDetail shows it through
  `src/components/RoutingSuggestionSection.vue`, whose Assign writes
  `lead.assignee`.
- **Schemas.** `lib/Settings/pipelinq_register.json`: `agentProfile` has
  `userId, skills, maxConcurrent, isAvailable`; `skill` has `title,
  description, categories, isActive`; `lead` has `source, category, assignee,
  stage, status`.
- **Creation hook.** `lib/Listener/DealCreatedListener.php` handles
  OpenRegister's `ObjectCreatedEvent` for leads (registered in
  `lib/AppInfo/Application.php:205`) and already defaults the forecast category.

## Decisions

### D1. Reuse the suggestion, add the turn

`LeadRotationService::assign(lead)` calls `RoutingService::findMatchingAgents()`
and the availability filter, then drops profiles with `pauseLeadAssignment`
true and those whose leads assigned in the last 30 days reached
`leadCapPer30Days`. From the rest it picks the one with the fewest leads
assigned in the last 30 days; a tie goes to the one whose last assignment is
oldest. That is "in turn" measured by outcome, so a colleague back from leave
does not receive a backlog in one morning.

### D2. Only new, unassigned leads, only when switched on

`DealCreatedListener` calls the service when app config
`lead_rotation_enabled` is true and the new lead has no `assignee`. A lead made
by a person who fills in an assignee is left alone. The write goes through
OpenRegister's object service like the listener's existing forecast default.

### D3. The lead says why

The service writes `assignee` and `assignmentReason`, for example "rotation:
fewest leads in 30 days (3 of cap 10), skill Solar". When nobody is eligible it
writes only `assignmentReason: rotation found no eligible colleague`, so the
unassigned lead is explained on LeadDetail, where the suggestion still works.

### D4. Settings

Admin settings get one switch, Assign new leads automatically. The agent
profile form gets Lead cap per 30 days (empty means no cap) and Pause lead
assignment.

## Risks

- Counting leads per person per 30 days is a query per candidate. Candidates are
  the few profiles with a matching skill; the count uses the `assignee` facet
  and a created-date filter, bounded like `getAgentWorkload`.
- Two leads created in the same second can go to the same person. Acceptable:
  the next lead corrects it, and caps still hold.
