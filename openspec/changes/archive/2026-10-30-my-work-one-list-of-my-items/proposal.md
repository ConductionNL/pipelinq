# My work lists all my items in one list

## Why
Board PqMijnWerk draws three lists, "Vandaag beantwoorden", "Wacht op mij" and "Wacht op de klant". Each one mixes tickets, leads and follow-ups, and each row carries a type pill (Verzoek, Klacht, Melding, Opvolging, Lead), the priority and a due text. Board PqMobiel draws the same items on a phone, grouped by when they are due: "Te laat", "Vandaag", "Deze week af". Live lists only tickets in the three lists, and adds two separate cards, "Mijn leads" and "Mijn opvolgingen". On a phone it shows the desktop dashboard squeezed into one column.

## What changes
- A generic endpoint, `GET /api/my-work/items?bucket=answer-today|waiting-on-me|waiting-on-customer`, merges the reader's open tickets, leads and follow-up tasks into one shape: `{ id, kind, typeLabel, title, subtitle, priority, dueAt, dueText, bucket, route, routeId }`. Each schema's own fields map to that shape through config, so the code names no procedure.
- The three lists read that endpoint (`endpointSource`). Their rows draw the type pill, the priority and the due text.
- "Mijn leads" and "Mijn opvolgingen" stay reachable through the lens chips (Leads, Opvolgingen). Under "Alle" their items are in the three lists.
- On a narrow screen the page draws one list, grouped by due (`groupBy` on a `dueGroup` field: late, today, this week), with the lens chips scrolling sideways. This needs a dashboard "narrow presentation" in nc-vue, or a separate phone view of the page.

## Open question for the board owner
Does "Wacht op mij" mean tickets that are `in_progress`, plus leads and tasks that are due? The board's rows suggest yes.
