# Proposal: werkplek-in-call-tiles-and-header

## Why

pl4 on board PqWerkplekKlant, after werkplek-in-call-columns. The in-call page still lacks three things the board draws:

- the row of six tiles above the cards: Nieuw verzoek, Terugbellen, Afspraak boeken, Naar backoffice, Klacht
  registreren and Ander kanaal;
- the header: "In gesprek" as a status menu with its own timer chip, and the pills "Telefoon, inkomend" and
  "Geïdentificeerd" on the line under the title;
- the card titles "Dit contactmoment", "Openstaande zaken" and "Eerder contact".

## What changes

- The simple profile's contact centre page gets a `simple-call-actions` widget (nextcloud-vue `action-tiles`), shown in
  a call only. Each tile opens its step for the client on the line (`@workspace.selectedClient`): the new request
  dialog, a callback task, an appointment booking, a follow-up task for the back office, a complaint and a contact
  moment on another channel. The in-call layout puts the row across the top and moves the columns down by two rows.
- The header's status control reads "In gesprek" during a call and opens a menu that still sets the availability. The
  running time is a separate chip, and "Beëindigen en afronden" carries the hang-up icon.
- A call writes `callIdentified` into the workspace; the page declares `headerPills` (nextcloud-vue
  dashboard-header-pills) for `callActive` and `callIdentified`.
- The in-call layout titles its cards per placement (nextcloud-vue dashboard-placement-title). Without a call the cards
  keep their dashboard titles.

## Not in this change

- The breadcrumb line "Dashboard / Klant aan de lijn · nummer … · gevonden in de klantgegevens · 10:24".
- Hiding "Contact vastleggen" during a call: header actions do not follow a workspace change yet.
- The card subtitles and links ("Opgeslagen terwijl je typt", "2 tickets, 1 zaak", "Alle 3").
