# client-letters Specification (delta)

## Purpose

A user makes a printable letter for one client from a template, filled with
that client's details, and the letter shows on the client's timeline. filinq
renders it. From pipelinq matrix row `work-letter-template`.

## ADDED Requirements

### Requirement: A user makes a letter for a client from a filinq template (REQ-WLT-001)

When filinq is installed, ClientDetail and TicketDetail SHALL offer Make a
letter. The dialog SHALL list the filinq templates in the `pipelinq`
namespace. The system SHALL have filinq fill the chosen template with the
client, the chosen contact person and, from a ticket, that ticket, and SHALL
return the letter as a PDF and keep a copy in the user's Files.

#### Scenario: An account manager prints an appointment letter

- GIVEN filinq is installed with a pipelinq template named Appointment letter
- WHEN an account manager on ClientDetail chooses Make a letter, picks Appointment letter and presses Make letter
- THEN a PDF downloads with the client's name and address filled in
- AND a copy of the PDF is in the account manager's Files

#### Scenario: A KCC employee writes to the resident about their request

- GIVEN a request ticket for a resident client
- WHEN a KCC employee chooses Make a letter on TicketDetail and picks a template that quotes the ticket subject
- THEN the PDF shows the resident's name and the ticket subject

### Requirement: A letter is logged on the client (REQ-WLT-002)

After a letter is made, the system SHALL log it as an outgoing contact moment
on the client with channel letter, naming the template and the file. When the
letter was made from a ticket, the contact moment SHALL hang under that ticket.

#### Scenario: The timeline shows the letter

- GIVEN an account manager made an Appointment letter for a client
- WHEN a colleague opens that client's activity
- THEN a contact moment "Letter: Appointment letter" is listed, outgoing, by letter

### Requirement: Without filinq no letter is offered or faked (REQ-WLT-003)

When filinq cannot be reached, the system SHALL NOT offer Make a letter, and
the letter endpoint SHALL answer that filinq is unavailable. It SHALL NOT
return a placeholder document.

#### Scenario: An instance without filinq

- GIVEN filinq is not installed
- WHEN a user opens ClientDetail
- THEN Make a letter is not in the Actions menu
- AND a direct `POST /apps/pipelinq/api/clients/{id}/letters` answers 503 naming filinq
