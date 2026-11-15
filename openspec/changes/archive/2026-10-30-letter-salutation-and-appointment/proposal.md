---
kind: code
depends_on: [letter-preview-and-fields]
---

# Proposal: letter-salutation-and-appointment

## Why

PqBriefOpstellen draws a letter that opens with "Geachte mevrouw De Vries" and lists four filled fields: Aanhef, Adres, Kenmerk and Afspraak ("14 oktober, 10:30, uit Afspraken"). pipelinq can fill neither of the two outer ones today:

- The client has no salutation. Its only name is the full name, so a template can print "Geachte Sanne de Vries" at best. Deriving "mevrouw De Vries" from a full name guesses a gender and a surname, and both guesses go wrong for real people.
- The letter reads the client, the contact and the ticket. The appointment is not passed to filinq, so a template cannot print it.

## What changes

- The client and the contact person get `salutation`: how the person asked to be addressed at the top of a letter, such as "Geachte mevrouw De Vries". It is stated, never derived from a name or a gender, and unset means unset. A template prints `{{ client.salutation }}`; filinq's preview shows an unset one as not filled in.
- `POST /api/clients/{id}/letters` and `/letters/preview` take an optional `appointmentId`. pipelinq reads the appointment as the caller and hands it to filinq as `appointmentBooking` beside the client, contact and ticket. An appointment the caller cannot read, or one of another client that is not on the chosen ticket, is 404.
- The letter dialog passes the client's next appointment that is not cancelled, the one on the letter's ticket first.

## Out of scope

- The template itself (filinq data): its Aanhef field moves from `client.name` to `client.salutation` and it gains an Afspraak field on `appointmentBooking.startAt`. That is an example-set change in filinq.
- A picker for the appointment in the dialog. The board draws none.
