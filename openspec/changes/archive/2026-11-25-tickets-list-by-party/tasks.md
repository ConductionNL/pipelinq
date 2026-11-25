# Tasks: tickets-list-by-party

- [x] 1.1 Tests: `TicketLeafProviderTest::testClientIdsOfOneMasterEntity`, `TicketPartyControllerTest` (one client, several, none); red before.
- [x] 1.2 `clientIdsOf`, the controller and the route.
- [x] 1.3 Live: dossiq's "Alle 2 tickets bekijken" opens Sanne de Vries's tickets in pipelinq on :8080.
  - Live 25 Nov 2026 on a sandbox Nextcloud 34 + MariaDB + OpenRegister main with pipelinq, dossiq and opencatalogi development and the Zuiddrecht set: on dossiq's contact page of Sanne de Vries (brpPerson, masterEntityRef ...0012) the Tickets card's header link reads "View all 3 tickets" (en locale; the count is that card's, the board draws 2) with href `/index.php/apps/pipelinq/tickets/party/0f5e0000-0000-4000-8000-000000000012`. Clicking it (Playwright, signed in) answers 303 to `/apps/pipelinq/tickets?client=dfbacc99-...` and the ticket list shows only Sanne de Vries's tickets (4 rows in the default view: Planning van het fietspad Lindelaan, Vraag over vergunning straatfeest Parkstraat, Lantaarnpaal kapot bij Lindelaan 40, Vraag over afvalinzameling; the API holds 7 for her client, the rest closed).
