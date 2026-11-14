# The client page's side cards: appointments, business and the knowledge base

## Why
PqKlant and PqOrganisatie draw three side cards the client page does not have: a resident's appointments, what the client does business on (projects and invoicing for a resident; sales, contracts and invoicing for an organisation, with a link to shillinq), and a knowledge base search.

## What changes
- Side column, after Relations: Appointments (resident), Projects and invoicing (resident) or Sales, contracts and invoicing (organisation), then Record history, then Knowledge base.
- GET /api/clients/{id}/business (ClientBusinessReader) answers the business sentences: open leads, running contracts, projects in planninq and invoices in shillinq, each left out when its app is missing, and where shillinq opens.
- nc-vue CnKbSearchWidget draws the side-card search in the board look.
