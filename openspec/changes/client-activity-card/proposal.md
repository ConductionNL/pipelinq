## Why

Boards PqKlant and PqOrganisatie end the client overview with an "Activiteit" card:
- "E-mail koppelen" and "Opstellen in Mail" in its header;
- a chip per kind: Alle, Contactmomenten, Notities, Taken, E-mail, Agenda, Berichten;
- a box for an internal note;
- feed rows with a round icon, a meta line and the text.

The contact strip has a fifth button, "Bericht sturen". pipelinq drew activity with its own ActivityTimeline, under the page body, and the timeline did not list notes or WhatsApp/SMS messages.

## What changes

- ClientDetail gets the library's `timeline` widget as the overview's last card, over `/api/timeline`, as manifest config. ActivityTimeline.vue and its registry entry go.
- `/api/timeline` lists two more kinds:
  - `note`: the notes on the record;
  - `message`: the WhatsApp/SMS messages of the client's contact persons.
- Every item carries `userName`, its author's display name.
- "Send a message" in the contact strip opens the Messages section's composer for the client. A sent message makes the Activity card read again.
- `EmailLinkDialog` is registered from the library for the header's "Link email".

## Impact

Stacked on DQ-L2's `pq-b5/client-detail-as-board` c7505ecc7. It needs nc-vue `feat/pq-activity-feed-board` (24t2l) and the nc-vue bump. Before them:
- the card draws the read view with button chips and no note box;
- "Link email" is not registered;
- the header pills fall back to buttons until DQ-L8's tonal variant is in.
