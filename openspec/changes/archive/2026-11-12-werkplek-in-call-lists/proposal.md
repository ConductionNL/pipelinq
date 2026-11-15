# Proposal: werkplek-in-call-lists

## Why

pl6 on board PqWerkplekKlant (30 Oct): in a call the open matters, the tasks and callback requests and the earlier
contact are tables with column heads and truncated titles. The board draws them as rows: a title, a meta line and a pill
("Uiterlijk 11 okt", "Klaar", "Open"), and "Alle 3" beside "Eerder contact". The header also still shows "Contact
vastleggen" next to "Beëindigen en afronden", and the BRP lookup has no icon.

## What changes

- The simple profile's in-call cards for open matters and earlier contact are their own record lists in rows, shown
  only in a call; the dashboard's "Client requests" and "Recent contact moments" tables stay as they are.
- "Tasks and callback requests" (in a call only) becomes a record list in rows with a status pill.
- Open matters: reference, type and assignee on the meta line; "Klaar" for a handled ticket, else "Uiterlijk {date}"
  when it has an SLA deadline.
- Earlier contact: date and time, channel and direction on the meta line, "Alle {total}" to the client's contact
  moments.
- "Log contact" leaves the header while a call is on (`visibleWhen`; needs nextcloud-vue
  action-visible-follows-workspace, before it the button stays as today).
- "Look up in the BRP" carries the id-card icon.

## Not in this change

- "Toevoegen" on the tasks card: a widget header action that opens a form is not wired for dashboard cards.
- The open dossiq case in open matters ("SLA nog 4 dagen"): open matters lists the client's tickets only.
- The column widths 332/415/332: the dashboard grid has twelve equal shares.
