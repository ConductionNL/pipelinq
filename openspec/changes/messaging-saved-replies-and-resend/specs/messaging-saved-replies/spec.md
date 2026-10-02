# messaging-saved-replies Specification (delta)

## Purpose

An agent answers a client with a saved reply on WhatsApp, SMS, the ticket's
message to the customer and email, and sends a failed WhatsApp or SMS message
again in one click. From pipelinq matrix rows `req-saved-replies` and
`cm-failed-send`.

## ADDED Requirements

### Requirement: The Messages section lists the contact's messages (REQ-MSR-001)

The Messages section on ClientDetail and ContactDetail SHALL fetch the
`channelMessage` records of the selected contact and SHALL list them newest
first with channel, direction, delivery status and time.

#### Scenario: An agent sees a message that was sent yesterday

- GIVEN a contact with one outbound SMS stored yesterday
- WHEN an agent opens that contact's detail page
- THEN the Messages section lists the SMS with its delivery status
- AND it does not say "No messages yet"

### Requirement: The team keeps saved replies (REQ-MSR-002)

The system SHALL store saved replies as `savedReply` records with a title, a
text, the channels they fit and an optional language. A Saved replies page
SHALL let a user with CRM access add, edit and switch off a saved reply.

#### Scenario: A team lead adds a saved reply

- GIVEN a team lead on the Saved replies page
- WHEN they add "Opening hours" with a text and the channels SMS and email
- THEN "Opening hours" appears in the list with those two channels

### Requirement: An agent inserts a saved reply where they answer (REQ-MSR-003)

The Send message dialog, the Reply to the customer section on a ticket and the
Write email dialog SHALL offer a saved reply picker that lists active replies
for that channel. Picking a reply SHALL put its text in the composer with
`{{client.name}}`, `{{contact.name}}`, `{{ticket.title}}` and `{{agent.name}}`
filled in, and SHALL NOT send anything. A placeholder without a value SHALL
stay in the text as written.

#### Scenario: An agent answers an SMS with a saved reply

- GIVEN a saved reply "Opening hours" for SMS with the text "Dear {{contact.name}}, we are open until five."
- WHEN an agent opens Send message for contact Jan de Vries, picks SMS and picks "Opening hours"
- THEN the message field reads "Dear Jan de Vries, we are open until five."
- AND nothing is sent until the agent presses Send

#### Scenario: A reply for email only is not offered on WhatsApp

- GIVEN a saved reply whose channels are email only
- WHEN an agent opens Send message on WhatsApp inside the session window
- THEN the saved reply picker does not list that reply

### Requirement: An agent answers the customer on the ticket (REQ-MSR-004)

TicketDetail SHALL show a Reply to the customer section on request and
complaint tickets with the customer's portal replies and a text area for the
message to the customer. Saving SHALL write the ticket's `customerMessage`.
Save and wait for the customer SHALL also set the status to
`awaiting_customer`. portaliq SHALL receive `customerMessage` for the
customer's requests and complaints.

#### Scenario: An agent answers a portal request and waits for the customer

- GIVEN a request ticket with one customer reply from the portal
- WHEN an agent opens the ticket, picks a saved reply, edits it and presses Save and wait for the customer
- THEN the ticket's message to the customer holds the edited text
- AND the ticket status is awaiting customer

#### Scenario: The customer reads the answer in portaliq

- GIVEN a request ticket with a message to the customer
- WHEN the customer opens that request in portaliq
- THEN the request shows the message to the customer
- AND the ticket's internal notes are not shown

### Requirement: An agent starts an email from a saved reply (REQ-MSR-005)

Each email address in the channel section of a client or contact SHALL offer
Write email. The dialog SHALL let the agent enter a subject and pick a saved
reply for email, then open the Nextcloud Mail composer with the address,
subject and text filled in. When Mail is not enabled, the dialog SHALL open a
`mailto:` link with the same values.

#### Scenario: An agent writes an email in Mail from a saved reply

- GIVEN a contact with the address jan@example.nl and the Mail app enabled
- WHEN an agent presses Write email, types a subject, picks a saved reply and presses Open in Mail
- THEN a new tab opens the Mail composer addressed to jan@example.nl with that subject and text

### Requirement: An agent sends a failed message again (REQ-MSR-006)

A failed or expired outbound WhatsApp or SMS message in the Messages section
SHALL show Send again. Send again SHALL send the same text, or the same
template with its parameters, through the same consent, budget and window
checks as a new message. The new message SHALL be linked to the failed one, and
the failed message SHALL keep its status. The server SHALL refuse Send again on
a message that is not failed or expired, on an inbound message and on a message
that was already sent again.

#### Scenario: An agent sends a failed SMS again

- GIVEN an outbound SMS to a contact with delivery status failed
- WHEN an agent presses Send again on that message
- THEN a new outbound SMS with the same text appears at the top of the Messages section
- AND the failed message still shows failed

#### Scenario: A closed WhatsApp window asks for a template

- GIVEN a failed free-text WhatsApp message whose 24-hour window has closed
- WHEN an agent presses Send again
- THEN the Send message dialog opens on WhatsApp and asks for an approved template
- AND no message is sent

#### Scenario: A message cannot be sent again twice

- GIVEN a failed SMS that was already sent again
- WHEN a client calls the resend endpoint for it
- THEN the server answers with a conflict and sends nothing
