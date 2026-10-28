# client-detail (delta)

## ADDED Requirements

### Requirement: The client page shows appointments, business and the knowledge base beside it
The client page's side column SHALL show, after Relations, a resident's coming appointments (subject and time linking to the booking, status and handler under it), the business card (Projects and invoicing for a resident, Sales, contracts and invoicing for an organisation), Record history, and a Knowledge base search.

#### Scenario: A resident's appointment
- GIVEN Sanne de Vries has a confirmed site visit "Locatiebezoek Parkstraat" handled by Pieter Jansen
- WHEN her client page opens
- THEN the Appointments card reads "Locatiebezoek Parkstraat, <day>, 14:00" with "Confirmed · with Pieter Jansen" under it

### Requirement: The client page says what the client does business on
GET /api/clients/{id}/business SHALL read the client with the caller's rights and answer sentences for its open leads, running contracts, projects in planninq and invoices in shillinq, leaving out the sentence of an app that is not installed, and the address of shillinq when it is.

#### Scenario: A resident with a planned project and no invoices
- GIVEN a resident with one planned project and no invoices in shillinq
- WHEN the business card loads
- THEN it reads "1 project: <title>, planned. No invoices for this resident." with a link Further in Shillinq
