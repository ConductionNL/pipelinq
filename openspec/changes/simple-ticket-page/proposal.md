# Proposal: simple-ticket-page

## Why

The ticket page shows three cards and four sections, and no button that says
what to do next. A handler changes the status by editing the record. The
Zuiddrecht design (`Acties.dc.html`) gives a record page one primary button
that follows its stage, a few quick actions, a grouped menu, pills for type and
status, and a side column.

## What changes

Only the simple structure changes. The page gets its shape from an overlay in
`src/menu-layout.simple.json`. The full structure keeps today's page, and a
spec asserts that.

| Part | In the simple structure |
| --- | --- |
| Primary button | New: In behandeling nemen. In progress: Beantwoorden. Waiting for customer: Beantwoorden |
| Quick actions | Beantwoorden, Contact vastleggen |
| Menu "Meer" | Status: Terug naar in behandeling. Afronden: Afronden, Oplossen, Afsluiten, Afwijzen |
| Pills | Ticket type and status, above the title |
| Side column | Klant (client, contact, channel), Deadline (SLA deadline, priority, assignee), Gekoppelde zaak |
| Body | One tabs widget: Overzicht, Contactmomenten, Gerelateerd, Besluiten |
| Sections | Asked about, Conversation (read), Woo request, Suggested agents, Case |

Every status change is a transition of the ticket schema's
`x-openregister-lifecycle`, posted to OpenRegister's transition endpoint. That
endpoint checks the current status and the authorization. A button only hides
on a status its transition cannot leave. It decides nothing.

Beantwoorden opens a dialog. Its body is `CustomerReplySection`, unchanged: the
same text area, the same two save buttons, the same save. In the simple
structure the section leaves the page body and the body shows the conversation
to read, so an answer is written in one place.

Contact vastleggen opens the ticket form with the type set to contact moment
and the ticket, client and contact filled in. The new tab Contactmomenten lists
what was logged.

Convert to case is back on the page. `RequestConversionSection` was written for
this page and was mounted nowhere since the ticket types were unified. It asks
the server whether a case app is installed and hides when not.

## What the design asks for that pipelinq does not have

- **Herinneren.** Pipelinq has no reminder to a customer who does not answer.
  Reminders exist for bookings, contracts and callbacks only. The waiting stage
  offers Beantwoorden, which sends a new message.
- **Afsluiten after answering.** Resolved, completed, rejected, converted and
  closed are all final in the lifecycle. A finished ticket has no next step and
  no primary button. Finishing is done from in progress, with the transition
  that belongs to the ticket type.
- **One finish button.** A request is completed, a complaint is resolved and a
  contact moment is closed. Three transitions, one per type. The stage is the
  status alone, so the primary button cannot pick between them. They are in the
  menu, each shown for its own type.
- **A primary button on a contact moment in progress.** There is no customer to
  answer, so Beantwoorden hides and the page shows no primary button. Afsluiten
  is in the menu.
- **Convert to case and convert to Woo request as header buttons.** Both need
  an availability answer from the server first. They stay self-hiding sections.
- **The library's conversation widget.** It reads one field of
  `{ author, time, text, side }`. A ticket keeps two lists, `portalReplies` and
  `portalAnswers`, of `{ message, createdAt }`. So a small section merges them
  and hands them to the library's thread component, read only.
- **The customer's first message in the thread.** A ticket's `description` is
  the question on a portal ticket and an internal note on others. Nothing says
  which, so it is not in the thread.
- **A "Wat nu?" checklist.** Not built. A ticket has no fields a checklist
  could tick off honestly.
- **Counts on tabs.** A ticket carries no count of its contact moments.

## Who may do what

Unchanged. The page had no status buttons. The new ones call the lifecycle
endpoint, which already decided who may run each transition. No action is admin
only. Editing the record works as before.

## Impact

No schema change, no register version bump, no migration.
