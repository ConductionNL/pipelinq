# Client letters

Make a printable letter for one client from a template. filinq fills in the client's details and hands you a PDF. A copy lands in your Files, and the letter shows on the client's activity as an outgoing contact moment.

## Specs

- `openspec/specs/client-letters/spec.md`

## Make a letter

1. Open a client, or a ticket of that client.
2. Open the Actions menu and choose **Make a letter**.
3. Pick a template. Pick a contact person if the letter is addressed to one.
4. Press **Make letter**.

The PDF downloads straight away, so you can print it. The copy in your Files sits in filinq's output folder.

From a ticket, the letter can quote the ticket, for example its title. The contact moment then sits under that ticket.

After the render, the dialog lists anything filinq wants you to check. A field the template names but the client lacks is one example: that spot in the letter stays empty.

## Without filinq

Letters are made by filinq. When filinq is not installed, **Make a letter** is not in the Actions menu. A direct call to the letter endpoint answers 503 and names filinq. pipelinq never makes a placeholder letter.

## For administrators: write a template

Write letter templates in filinq's template editor.

- Set the namespace to `pipelinq`. Only templates in that namespace appear in the dialog.
- The template is Twig. It reads three keys:
  - `client`: the client, for example `{{ client.name }}` and `{{ client.address }}`.
  - `contact`: the contact person, when the user picked one, for example `{{ contact.name }}`.
  - `ticket`: the ticket, when the letter is made from one, for example `{{ ticket.title }}`.
- A salutation by gender is not possible: the client and contact records hold no gender.

## API

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/api/letters/templates` | Whether filinq can make a letter, and pipelinq's templates |
| POST | `/api/clients/{id}/letters` | Make a letter: `templateId`, optional `contactId` and `ticketId` |

Every record is read with your own rights. A client, contact or ticket you cannot read answers 404.
