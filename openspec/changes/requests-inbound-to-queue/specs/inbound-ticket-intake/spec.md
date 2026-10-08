# inbound-ticket-intake Specification (delta)

## Purpose

A message from outside becomes a ticket in the Queue, next to mail and phone,
and an administrator routes new tickets to the right team by rules they manage
themselves. From pipelinq matrix rows `cm-social-media` and `req-mail-routing`.

## ADDED Requirements

### Requirement: An inbound message becomes a ticket in the Queue (REQ-ITQ-001)

The system SHALL turn an inbound WhatsApp message, SMS, mail to a ticket
mailbox, Facebook page message or Instagram message into a ticket of type
request with its channel, its sender, the matched client or contact, status
new and no assignee. The ticket SHALL appear in the Queue. An opt-in or opt-out
keyword message SHALL NOT open a ticket.

#### Scenario: A WhatsApp question lands in the Queue

- GIVEN no open ticket for the WhatsApp conversation with contact Fatima
- WHEN Fatima sends "Is my permit ready?" on WhatsApp
- THEN the Queue shows a new request ticket with channel whatsapp for Fatima
- AND nobody is assigned to it

#### Scenario: A STOP message opens no ticket

- GIVEN a contact who writes STOP on WhatsApp
- WHEN the message arrives
- THEN the opt-out is recorded
- AND no ticket is created

### Requirement: A follow-up stays on its ticket (REQ-ITQ-002)

A message on the same WhatsApp or SMS conversation, the same mail thread or
the same social conversation SHALL be added to the open ticket for that thread
instead of opening a new one. A follow-up on a ticket that is awaiting the
customer SHALL move it to in progress. When the earlier ticket is closed, a new
message SHALL open a new ticket.

#### Scenario: A client replies to the same mail thread

- GIVEN an open ticket created from a mail with subject "Invoice 2026-114"
- WHEN the client replies in the same mail thread
- THEN the Queue still shows one ticket for that thread
- AND the reply is linked to that ticket

#### Scenario: A reply to a question moves the ticket back to work

- GIVEN a WhatsApp ticket in status awaiting customer
- WHEN the contact answers on the same conversation
- THEN the ticket status is in progress

### Requirement: An administrator names the ticket mailboxes (REQ-ITQ-003)

The admin settings SHALL let an administrator choose Nextcloud Mail accounts as
ticket mailboxes. The system SHALL read new mail in the inbox of each ticket
mailbox, match the sender to a client or contact by address or domain, and
link the mail to its ticket. Mail sent from a ticket mailbox's own address
SHALL NOT open a ticket. Only an administrator SHALL change the list.

#### Scenario: Mail to the service address becomes a ticket

- GIVEN the Mail account for service@gemeente.nl is a ticket mailbox
- AND a client with the address info@bakkerij.nl
- WHEN that client mails service@gemeente.nl
- THEN within five minutes the Queue shows a request ticket with channel email linked to that client

#### Scenario: A colleague cannot change the ticket mailboxes

- GIVEN a user without administrator rights
- WHEN they call the ticket mailbox account list
- THEN the server refuses with 403

### Requirement: Facebook and Instagram messages reach the Queue (REQ-ITQ-004)

For each connected Facebook page and Instagram business account, the system
SHALL read new conversations through the credential broker and pass each new
visitor message to the intake. When the broker or the network refuses, the
account SHALL show the reason.

#### Scenario: A Facebook message lands in the Queue

- GIVEN a connected Facebook page whose messaging permission is approved
- WHEN a visitor sends the page "Are you open on Saturday?"
- THEN the Queue shows a request ticket with channel facebook and the visitor's name

#### Scenario: A page without messaging permission says so

- GIVEN a connected Facebook page whose messaging permission Meta has not approved
- WHEN the inbox job runs
- THEN the Social accounts page shows Meta's refusal on that account
- AND no ticket is created

### Requirement: An administrator routes new tickets by rules (REQ-ITQ-005)

The system SHALL ship a flow that sets the category and priority of a new
inbound ticket from an ordered table of rules on channel, sender address,
sender domain, mailbox and client segment. The flow SHALL be off until an
administrator adopts it. A Routing rules page SHALL let an administrator add,
reorder and remove rules and SHALL show OpenRegister's refusal of a rule it
cannot run. Each routed ticket SHALL record which rule matched.

#### Scenario: Mail from a partner domain goes to its team

- GIVEN an adopted routing flow with the rule "sender domain kadaster.nl sets category Registratie and priority high"
- WHEN mail from someone@kadaster.nl arrives in a ticket mailbox
- THEN the new ticket has category Registratie and priority high
- AND the ticket records the rule that matched

#### Scenario: A team opens its part of the Queue

- GIVEN open unassigned tickets in the categories Registratie and Belastingen
- WHEN a team member opens the Queue and picks the category Registratie
- THEN only the Registratie tickets are listed

#### Scenario: Routing is off until someone switches it on

- GIVEN a fresh installation
- WHEN mail arrives in a ticket mailbox
- THEN the ticket lands in the Queue with no category
- AND the Routing rules page offers to switch routing on
