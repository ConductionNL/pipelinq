# notifications-activity Specification (delta)

## Purpose

Colleagues who follow a ticket or a lead are told about it like its assignee.
From pipelinq matrix row `work-collaborators`.

## ADDED Requirements

### Requirement: Followers of a ticket or lead receive its update notifications (REQ-NFTL-001)

The `ticket` rule `ticketUpdated` and the `lead` rules `leadUpdated`,
`leadWon` and `leadLost` SHALL each declare the recipient block
`{"watchers": true}` in `x-openregister-notifications`, next to the
recipients they declare today. The system SHALL rely on OpenRegister to
resolve that block to the users who follow the object, to deduplicate them
against the other recipients and to skip a follower who may no longer read
the object.

#### Scenario: A follower hears about a ticket change

- GIVEN a ticket assigned to Sanne and followed by Jeroen
- WHEN Sanne changes the ticket's status
- THEN Jeroen receives a Nextcloud notification "Ticket changed: <title>"
- AND Sanne receives the same notification as today

#### Scenario: A follower hears that a lead was won

- GIVEN a lead followed by Jeroen, who is not its assignee and not in the `sales` group
- WHEN the lead is moved to won
- THEN Jeroen receives the lead won notification

#### Scenario: A follower who is also the assignee is told once

- GIVEN a lead assigned to Jeroen and followed by Jeroen
- WHEN the lead is changed
- THEN Jeroen receives one "Lead changed" notification, not two

#### Scenario: A ticket nobody follows notifies as before

- GIVEN a ticket with no followers
- WHEN the ticket is changed
- THEN only the assignee is notified, as before this change

### Requirement: Adding followers keeps every existing recipient (REQ-NFTL-002)

The register configuration SHALL keep the recipients that each of the four
rules declares today: the assignee field on all four and the `sales` group on
`leadWon`. Because a register fragment replaces a list instead of merging it,
the fragment that adds `{"watchers": true}` MUST restate the full recipient
list of each rule.

#### Scenario: The merged register keeps the assignee and the sales group

- GIVEN the monolith register and every fragment in `lib/Settings/register.d/`
- WHEN they are merged in sorted filename order
- THEN `lead.leadWon` has the recipients `sales` group, the `assignee` field and `{"watchers": true}`
- AND `ticket.ticketUpdated`, `lead.leadUpdated` and `lead.leadLost` each have the `assignee` field and `{"watchers": true}`

#### Scenario: Create rules do not address followers

- GIVEN the merged register
- WHEN the recipients of `ticket.newTicket` and `lead.newLead` are read
- THEN neither contains a watchers block

### Requirement: The watchers block passes OpenRegister's schema validation (REQ-NFTL-003)

Each watchers block SHALL be spelled `{"watchers": true}`, a boolean with no
`kind` key, so that OpenRegister's notification validator accepts the schema
on import.

#### Scenario: The register imports without a partial import

- GIVEN a Nextcloud instance with OpenRegister on a version that includes object watchers
- WHEN pipelinq's register is imported after an upgrade
- THEN the import finishes with no `PARTIAL IMPORT` in the log
- AND the `ticket` and `lead` schemas read back through OpenRegister carry the watchers block on the four rules

### Requirement: A user adds a colleague to a ticket or lead as a follower (REQ-NFTL-004)

The ticket and lead pages SHALL let a user add a colleague as a follower, after which that colleague receives the record's update notifications.

#### Scenario: Sanne adds Jeroen to her ticket

- GIVEN a ticket assigned to Sanne
- WHEN Sanne adds Jeroen as a follower and then changes the ticket's status
- THEN Jeroen is listed as a follower on the ticket
- AND he receives the ticket changed notification

### Requirement: A colleague added to a ticket or lead can work on it (REQ-NFTL-005)

Adding a colleague to a ticket or lead SHALL let that colleague open and change it, also when their own role would not give them that record. Removing them SHALL take that access away again.

#### Scenario: Sanne adds Jeroen to her ticket
- GIVEN ticket "Parking permit" assigned to Sanne, which Jeroen's role does not let him read
- WHEN Sanne adds Jeroen to the ticket
- THEN Jeroen can open the ticket and change its status
- AND after Sanne removes Jeroen, he can no longer open it
- @e2e exclude to be covered with task 5.1
