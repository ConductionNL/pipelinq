# Lead Management — Quotation System Delta

## ADDED Requirements

_(none — new requirements are in quotation-management spec)_

## MODIFIED Requirements

### Requirement: REQ-LEAD-004: Lead Detail View [MVP]

The system MUST provide a detail view for each lead that displays all properties, pipeline progress, linked entities, activity timeline, and linked quotations. The layout MUST follow the wireframe in DESIGN-REFERENCES.md Section 3.4.

#### Scenario: View lead detail -- core information

- GIVEN a lead "Acme Corp deal" with value EUR 5,000, probability 40%, source "Website", priority "Normal", category "Consulting", expectedCloseDate "2026-03-05"
- WHEN the user navigates to the lead detail view
- THEN the system MUST display the Core Info panel with all properties
- AND value MUST be formatted with currency symbol and thousand separators ("EUR 5,000")
- AND probability MUST be displayed as a percentage ("40%")
- AND expected close date MUST be formatted in the user's locale

#### Scenario: View lead detail -- pipeline progress indicator

- GIVEN a lead on the "Sales Pipeline" currently in stage "Qualified" (3rd of 7 stages)
- WHEN the user views the lead detail
- THEN the system MUST display a vertical pipeline progress indicator showing all stages
- AND completed stages (New, Contacted) MUST be shown with a filled indicator
- AND the current stage (Qualified) MUST be highlighted as "current"
- AND future stages (Proposal, Negotiation, Won, Lost) MUST be shown with an empty indicator
- AND a "Move to Proposal" action button MUST be displayed to advance to the next stage
- AND the pipeline name ("Sales Pipeline") MUST be displayed below the progress indicator

#### Scenario: View lead detail -- client and contact links

- GIVEN a lead linked to client "Acme Corporation" (email: info@acme.nl, phone: +31 20 555 0123) and contact "Petra Jansen" (email: petra@acme.nl, role: Sales Manager)
- WHEN the user views the lead detail
- THEN the client name MUST be a clickable link navigating to the client detail view
- AND the client's email and phone MUST be displayed
- AND the contact person's name, email, and role MUST be displayed
- AND if no client is linked, the system MUST show "No client linked" with an "Add client" action

#### Scenario: View lead detail -- assignee section

- GIVEN a lead assigned to Nextcloud user "jan" (display name "Jan de Vries")
- WHEN the user views the lead detail
- THEN the system MUST display the assignee's display name and avatar
- AND a "Reassign" button MUST be present to change the assignee
- AND if no assignee is set, the system MUST show "Unassigned" with an "Assign" action

#### Scenario: View lead detail -- activity timeline

- GIVEN a lead with the following history:
  - Feb 10: Lead created (source: Website, by: system)
  - Feb 15: Stage changed to "Contacted" (by: Jan de Vries)
  - Feb 18: Note added: "Had a great call with Petra..." (by: Jan de Vries)
  - Feb 20: Stage changed to "Qualified" (by: Jan de Vries)
- WHEN the user views the lead detail
- THEN the activity timeline MUST display events in reverse chronological order (newest first)
- AND each event MUST show: date, event description, and actor
- AND an "Add note" button MUST be present at the top of the timeline
- AND the timeline MUST support pagination for leads with extensive history

#### Scenario: View lead detail -- quotations section

- GIVEN a lead with 2 linked quotations:
  - Q-2026-00012: "Website Redesign" (status: sent, grandTotal: EUR 15,000, validUntil: 2026-04-01)
  - Q-2026-00015: "Website Redesign v2" (status: draft, grandTotal: EUR 12,500, validUntil: 2026-04-15)
- WHEN the user views the lead detail
- THEN the system MUST display a "Quotations" section listing all linked quotations
- AND each quotation row MUST show: reference number, title, status badge, grand total, valid until date
- AND each row MUST be clickable to navigate to the quotation detail view
- AND a "Create Quotation" button MUST be present to generate a new quotation from this lead
- AND if no quotations exist, the section MUST show "No quotations yet" with a "Create Quotation" action

## REMOVED Requirements

_(none)_
