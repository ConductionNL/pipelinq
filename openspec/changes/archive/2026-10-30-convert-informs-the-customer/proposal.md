# Converting a request can tell the customer the case number

## Why
Board PqTicketOmzetten offers "Klant informeren in Mijn Zuiddrecht": "Sanne ziet het zaaknummer en wie de zaak behandelt." The conversion created the case, but the customer heard nothing.

## What changes
- `POST /api/handoff/request/{id}/convert-to-case` accepts `informCustomer`. When it is set, the request is answered the way every answer reaches the portal: `customerMessage`, appended to `portalAnswers`. The answer names the case number (the case's `identifier`) and, once the case has one, its handler.
- The availability answer carries `portalName` (app config `portal_name`). The dialog names the portal with it, and the Zuiddrecht set seeds "Mijn Zuiddrecht".
