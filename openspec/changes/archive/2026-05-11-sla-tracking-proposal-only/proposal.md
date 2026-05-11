# Proposal: SLA Tracking and Escalation

## Status
PROPOSED

## Problem
There is no way to track response times or set SLA rules on requests and leads in Pipelinq. Municipalities and government organizations require measurable service levels for citizen interactions, and 620 tender sources explicitly demand SLA tracking capabilities in CRM solutions.

## Solution
Add SLA configuration per request type (response time, resolution time), visual indicators (on-time / at-risk / overdue), and escalation rules that auto-notify managers when SLA thresholds are breached.

## Features
- **SLA rule configuration** in admin settings, configurable per request type (response time target, resolution time target, warning threshold percentage)
- **Response time and resolution time tracking** on requests, calculated from creation to first response and creation to resolution
- **Visual SLA status indicators** (green = on-time, amber = at-risk, red = overdue) displayed on both list and detail views
- **Overdue counter** on dashboard KPI tiles showing number of requests currently past SLA
- **Escalation rules** that auto-notify the assigned user's manager via Nextcloud notifications when SLA is breached
- **SLA compliance reporting** in dashboard charts showing compliance rate over time, broken down by request type

## Standards
- TEC CRM 3.5 (Service Level Management)
- ITIL SLA practices
- GEMMA Dienstverleningsovereenkomsten

## Dependencies
- Nextcloud Notifications API (for escalation notifications)

## Demand
620 tender sources demand SLA tracking in CRM solutions.

## Risks
- SLA clock calculation must account for business hours and holidays (not just elapsed time)
- Escalation notifications require manager relationship to be defined (team/user hierarchy)
