# Proposal: tickets-list-by-party

## Why

Dossiq's contact and organisation pages show a party's open pipelinq tickets (through the ticket leaf, by master
entity). Boards DqContact and DqOrganisatie head that card with "Alle 2 tickets bekijken", a link to the party's tickets
in pipelinq. Dossiq knows the party by its master entity, not by pipelinq's client uuid, and pipelinq had no address
that opens the ticket list for a master entity.

## What changes

`GET /apps/pipelinq/tickets/party/{ref}` resolves the clients that share master entity `ref` (read through
OpenRegister as the caller) and redirects to the ticket list filtered on them: `/tickets?client=<id>`, or
`client[]=` for several. A party without a readable client opens the whole list. `TicketLeafProvider::clientIdsOf`
answers the lookup, reusing the leaf's own client read.

## Scope

- `lib/Integration/TicketLeafProvider.php`: `clientIdsOf`.
- `lib/Controller/TicketPartyController.php` + route `ticketParty#tickets`.
