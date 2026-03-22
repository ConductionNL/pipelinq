# Notifications & Activity Stream Specification

## Problem
Deliver real-time notifications and a team-visible activity timeline for CRM events so users stay informed about leads, requests, and collaboration actions. This spec covers the notification dispatch logic, activity stream integration, per-category user preferences, notification rendering, and CRM-specific event types including SLA breach warnings, deal won celebrations, and quote lifecycle events.
**Feature tier:** V1 (core notifications), Enterprise (SLA, advanced events)
**Competitor context:** EspoCRM provides granular notification settings per entity type with in-app, email, and webhook channels. Krayin CRM uses Laravel events for notification dispatch with configurable workflows. Twenty CRM has basic in-app notifications without per-category settings. This spec positions Pipelinq to leverage Nextcloud's mature notification and activity infrastructure (OCP APIs) while adding CRM-specific event intelligence.
---

## Proposed Solution
Implement Notifications & Activity Stream Specification following the detailed specification. Key requirements include:
- Requirement: CRM Notifications [V1]
- Requirement: CRM Activity Stream [V1]
- Requirement: Per-Category Notification Preferences [V1]
- Requirement: Notification Rendering [V1]
- Requirement: Deal Won Notification [V1]

## Scope
This change covers all requirements defined in the notifications-activity specification.

## Success Criteria
- Lead assignment notification
- Request assignment notification
- Note added notification
- Stage change notification
- Self-action does not notify
