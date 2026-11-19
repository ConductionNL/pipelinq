# Proposal: client-page-as-the-relation-boards

## Why

pipelinq's client page (ClientDetail) is drawn on the boards PqKlant (a resident) and PqOrganisatie (an organisation), and it should look like dossiq's contact and organisation pages: the contact and organisation screens are (almost) the same in pipelinq, dossiq and shillinq. The pl2 measure found the page laid out its own way: two rows of captioned figures, its own tab strip, Identity and Account cards in the overview, a sales block in the side column.

## What changes

- One row of six figures as label and value: open matters, new requests, term passed, term at risk, open leads (their value) and last activity (a date).
- The boards' tab strip: Overview, Tickets, Contact persons (an organisation), Contact moments, Sales (a resident), Documents, History.
- The overview opens on the resident's or organisation's card, the client's tickets as ruled rows and the three newest contact moments, with the record-list and facts widgets dossiq's DqContact and DqOrganisatie use.
- The header offers Log contact and New request for this client.
- The side column follows the boards' order with the widgets dossiq uses (reachable via, identity, address or KvK and address, main contact, open cases and tickets, contact channels, relations, appointments, projects and invoicing, history, knowledge base). Built in steps; a field dossiq does not have extends the widget, not the page.
- Cases (a dossiq source for the client) needs an endpoint by the client's master key and is a step of its own.

All of it is manifest configuration over CnDetailPage and the shared nextcloud-vue widgets.
