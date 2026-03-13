# Proposal: notifications-activity

## Problem

Pipelinq CRM actions happen silently — when a lead is assigned, a stage changes, or a request status updates, only the user performing the action sees it. Assignees have no idea they have new work, managers cannot track team activity, and there is no audit trail of what happened and when.

This means:
- Assigned leads/requests go unnoticed until the assignee manually checks My Work
- Stage changes and status updates are invisible to collaborators
- There's no unified timeline of CRM activity across the team
- No email digest or notification center integration for CRM events

## Solution

Add two complementary Nextcloud-native integrations:

1. **Notifications (#43)** — Push notifications to users when they are directly affected by CRM actions (lead assigned to them, note added on their item, etc.). Uses Nextcloud's `IManager` notification API, which delivers to the notification bell, desktop notifications, and mobile push.

2. **Activity stream (#42)** — Publish CRM events to Nextcloud's Activity app, creating a team-visible timeline. Uses `IManager` activity API with `IProvider`, `ISetting`, and `IFilter` to register Pipelinq events. Users can configure which events they see and whether they receive email digests.

Both are backend-only with minimal frontend work — Nextcloud's existing notification center and Activity app handle all UI rendering.

## Scope

- Backend PHP: NotificationService, Notifier (INotifier), ActivityService, ActivityProvider (IProvider), ActivitySetting (ISetting), ActivityFilter (IFilter)
- Registration in `info.xml` (notifications) and `Application.php` (activity)
- Integration points in existing ObjectStore save flows (lead/request create, update, assign, stage change)
- 7 event types: lead_created, lead_assigned, lead_stage_changed, request_created, request_status_changed, note_added, item_overdue

## Out of scope

- Custom notification UI (Nextcloud handles this)
- Custom activity feed UI (Activity app handles this)
- Email templates beyond what Nextcloud provides
- Notification preferences UI (Nextcloud Settings handles this)
- Webhook/external notification delivery (Enterprise tier)
