# client-letters Specification

## Purpose

A user makes a printable letter for one client from a template, filled with
that client's details, and the letter shows on the client's timeline. filinq
renders it. From pipelinq matrix row `work-letter-template`.

## Requirements

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

### Requirement: The letter dialog previews the letter and lists what was filled in

When a template is chosen, the letter dialog SHALL show filinq's preview of the letter for the client, and the ticket and contact if chosen. Beside the preview it SHALL list the fields filinq filled, each with its value and where the value came from. It SHALL add one line that says either that all fields are filled or which are still empty. A preview SHALL NOT make, file or log a letter. When the choice changes, the preview SHALL follow it.

#### Scenario: every field is filled
- GIVEN a client with a template whose four fields filinq fills
- WHEN the user opens the letter dialog
- THEN the preview shows the letter and the line reads "Alle 4 velden ingevuld, niets ontbreekt"

#### Scenario: a field stays empty
- GIVEN a template that prints an appointment the client does not have
- WHEN the preview is made
- THEN that field reads "Niet ingevuld" with its source, and the line names it as still empty

### Requirement: A letter can be downloaded without being filed

The dialog SHALL offer "Pdf downloaden". It SHALL return the letter as a PDF without a copy in the user's Files and without a contact moment on the client.

#### Scenario: download only
- GIVEN a chosen template
- WHEN the user chooses "Pdf downloaden"
- THEN the PDF downloads, and nothing is filed or logged

### Requirement: A person carries the salutation they asked for

A client and a contact person SHALL have a `salutation` field: the line that opens a letter to them, as they asked for it. pipelinq SHALL NOT derive it from a name, a gender or an address. A letter template SHALL be able to print it, and a preview SHALL show an unset salutation as not filled in.

#### Scenario: a salutation fills the Aanhef field
- GIVEN client Sanne de Vries with salutation "Geachte mevrouw De Vries"
- AND a template whose Aanhef field reads `client.salutation`
- WHEN a KCC employee opens the letter dialog on her page
- THEN the preview opens with "Geachte mevrouw De Vries" and Aanhef is filled
- @e2e exclude the letter needs filinq with a pipelinq template, which the e2e stack does not install; LetterControllerTest and the schema test cover it

#### Scenario: no salutation stays empty
- GIVEN a client without a salutation
- WHEN the preview is made
- THEN Aanhef reads "Niet ingevuld" and nothing is guessed
- @e2e exclude the letter needs filinq with a pipelinq template, which the e2e stack does not install; the schema test covers that nothing fills it

### Requirement: A letter reads the client's appointment

The letter endpoints SHALL take an optional `appointmentId` and pass that appointment to filinq as `appointmentBooking`, read as the caller. An appointment the caller cannot read, or one that belongs neither to the client nor to the chosen ticket, SHALL answer 404 and SHALL NOT reach filinq. The letter dialog SHALL pass the client's next appointment that is not cancelled, preferring one on the letter's ticket.

#### Scenario: the Afspraak field is filled
- GIVEN client Sanne de Vries with an appointment on 14 October at 10:30 for her open ticket
- WHEN a KCC employee opens the letter dialog on that ticket
- THEN filinq receives the appointment beside the client and the ticket, and Afspraak is filled
- @e2e exclude the letter needs filinq with a pipelinq template, which the e2e stack does not install; LetterControllerTest and letterFromTemplate.spec.js cover it

#### Scenario: another client's appointment is refused
- GIVEN an appointment of another client that is not on the chosen ticket
- WHEN a letter is asked for with its id
- THEN the answer is 404 and filinq is not called
- @e2e exclude a server guard, covered by LetterControllerTest
