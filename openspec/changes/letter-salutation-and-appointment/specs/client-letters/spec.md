## ADDED Requirements

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
