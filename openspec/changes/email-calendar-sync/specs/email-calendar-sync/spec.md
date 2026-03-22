# Email & Calendar Sync - Delta Spec

## Status: planned

## ADDED Requirements

### Requirement: Automatic email linking to CRM contacts

Inbound and outbound emails MUST be matched to Pipelinq contacts by email address.

#### Scenario: Incoming email matched to contact
- GIVEN contact "Jan de Vries" has email "jan@gemeente-utrecht.nl"
- AND the Nextcloud Mail app receives an email from that address
- WHEN the email sync background job runs
- THEN the system MUST create an EmailLink object linking the email to Jan's record

#### Scenario: Email matched to organization by domain
- GIVEN organization "Gemeente Utrecht" has domain "gemeente-utrecht.nl"
- AND an email arrives from "info@gemeente-utrecht.nl" (no matching contact person)
- WHEN the email sync runs
- THEN the email MUST appear in the organization timeline

### Requirement: Calendar sync for follow-ups

Follow-up calendar events MUST sync bidirectionally with Nextcloud Calendar.

#### Scenario: Create follow-up from lead
- GIVEN a user creates a follow-up on a lead
- WHEN the user sets a date and time
- THEN a CalDAV event MUST be created in the user's Nextcloud Calendar
- AND the event MUST link back to the lead
