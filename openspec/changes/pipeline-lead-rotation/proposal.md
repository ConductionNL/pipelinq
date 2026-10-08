---
kind: code
depends_on: []
---

# Proposal: pipeline-lead-rotation

## Summary

New leads reach the sales team in turn, each to the colleague who has had the
fewest lately, and nobody gets more than their cap. A colleague on leave can
pause their share. Today a handler opens every new lead and picks someone from
a ranked suggestion by hand.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27. It is
in the core area of the matrix (pipeline).

**`pipeline-lead-rotation`**, "Share new leads fairly across the sales team, with
a cap per person". Rated partial, built.state built. Matrix evidence: "no round
robin or auto assignment of leads (grep of lib for round robin, rotation, auto
assign). A handler gets a fair suggestion instead:
lib/Service/RoutingService.php:92 getSuggestedAgents ranks colleagues whose
skill covers the lead category, drops those unavailable or at their
maxConcurrent cap (:427 filterByCapacity) and orders the rest by open workload
... LeadDetail mounts src/components/RoutingSuggestionSection.vue ... whose
Assign writes the lead assignee". Note: "a ranked suggestion with a cap per
person, not an automatic rotation". Demand: changelog,
https://www.odoo.com/odoo-19-2-release-notes. One competitor rates it yes:

- odoo-crm: source read, `addons/crm/models/crm_team_member.py:22-23`
  "assignment_optout = fields.Boolean('Pause assignment')" and
  "assignment_max = fields.Integer('Average Leads Capacity (on 30 days)')" on the
  team member form.

hubspot-crm, pipedrive and espocrm rate it partial. The missing half is the
automatic, in-turn assignment of a new lead; the ranking by skill, availability
and workload already exists and is reused.

Archived proposals `2026-05-11-skill-routing` and `2026-06-14-lead-management`
listed round robin as "V2" and "separate V1 change": a deferral, not a no.

## What changes

- An administrator switches on automatic lead assignment.
- A colleague's agent profile gets a 30-day lead cap and a pause switch.
- When a lead is created without an assignee, pipelinq assigns it to the
  eligible colleague with the fewest leads in the last 30 days, under their cap.
- The lead records why it went to that person. When nobody is eligible, it stays
  unassigned and the suggestion on LeadDetail works as today.

## Out of scope

- Rotation for tickets. Tickets have the Queue (open change
  `retire-queue-concept`) and the same suggestion; rotating them is a separate
  decision.
- Territory rules (by postcode or region).

## Impact

- `agentProfile` gains `leadCapPer30Days` and `pauseLeadAssignment`; `lead` gains
  `assignmentReason`.
- New `lib/Service/LeadRotationService.php`, called from
  `lib/Listener/DealCreatedListener.php`.
- Admin settings: one switch; agent profile form: two fields.
