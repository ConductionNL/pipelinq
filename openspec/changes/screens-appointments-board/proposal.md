# Proposal: screens-appointments-board

## Why

The drawn boards are the UI canon. The appointments list (PqAfspraken), the appointment page (PqAfspraak) and its reschedule and cancel dialogs (PqAfspraakVerplaatsen) did not match theirs: the list was titled Boekingen with raw id columns and no period chips, the page showed the record as Boeking with no action row once a booking had passed, and the dialogs held only an input.

## What changes

- The booking schema gains three optional fields the boards show: `subject`, `location` and `assignee` (the handler, a Nextcloud user). Status and source values get labels (`lib/Settings/register.d/99-zz-appointment-board.json`). Shown after the register is imported again.
- The list is titled Appointments, with a count line, the chips Today, This week, Mine and Unconfirmed with their counts, selectable rows, the columns When, With whom, About, Location or desk, Handler and Status, and a footer note.
- The page sits on the relation frame: pills, breadcrumb, the five status actions as a button row (disabled while they do not apply), one tab strip (Overview, Resources, Notes, Documents, Related, History) and the customer and when-and-where cards beside it.
- The reschedule dialog shows the current and the new slot and what the customer receives; the cancel dialog names the appointment, the charge rule, the refund and what happens next.

Nothing is removed: the deposit stat moved into the data card, Send reminder stays in the action row when it applies, the timeline is the Overview's recent changes.
