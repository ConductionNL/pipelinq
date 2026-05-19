# Proposal: Request and Lead Routing

## Status
PROPOSED

## Problem
Requests and leads in Pipelinq are manually assigned to users or teams. This does not scale for organizations handling high volumes of incoming citizen requests. 620 tender sources ask for automated routing capabilities in CRM solutions.

## Solution
Add configurable routing rules that auto-assign incoming requests and leads to teams or users based on criteria such as type, source, geography, round-robin, or least-loaded distribution.

## Features
- **Routing rule configuration** in admin settings with priority ordering
- **Routing criteria**: request type, lead source, channel (phone, email, web form), geography (NUTS region / postcode range)
- **Assignment strategies**: round-robin, least-loaded (fewest open items), specific user, specific team
- **Auto-assign on creation** triggered when a new request or lead is created, with manual override always available
- **Routing history** visible on entity detail view showing assignment changes with timestamps and reasons
- **Fallback behavior**: items go to an unassigned queue if no routing rule matches, with notification to administrators

## Standards
- TEC CRM 3.4 (Case Routing)
- GEMMA Callcenter referentiecomponent (klantgeleiding)
- GEMMA Zaakgericht Werken (zaakroutering)

## Dependencies
- Nextcloud Groups API (for team-based assignment)
- Nextcloud Notifications API (for fallback notifications)

## Demand
620 tender sources demand automated routing in CRM solutions.

## Risks
- Routing rules can conflict; priority ordering and "first match wins" strategy needed
- Least-loaded strategy requires real-time counting of open items per user, which may have performance implications at scale
