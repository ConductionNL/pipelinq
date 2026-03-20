# Email & Calendar Sync

## Problem
No email or calendar sync functionality exists. Communication context is not captured in the CRM. Users must manually log interactions.

## Proposed Solution
Integrate with Nextcloud Mail and Calendar apps for bidirectional sync. Emails automatically linked to contacts by matching sender/recipient addresses. Calendar events for follow-ups synced with Nextcloud Calendar. Background job runs every 5 minutes for email matching.

## Impact
- Integration with Nextcloud Mail app (OCA\Mail)
- Integration with Nextcloud Calendar app (OCA\DAV)
- New EmailLink and CalendarLink schemas
- Background job for periodic sync
- Per-user sync configuration
