# Proposal: ticket-leaf-closed-reference-assignee

## Why

Board DqContact and DqOrganisatie draw a party's tickets like this:

- with their reference number, type and handler ("PQ-2026-0402 · Aanvraag · behandelaar Fatma Yildiz");
- with the handled tickets listed under the open ones ("Afgehandeld, klaar 29 sep").

The ticket leaf dossiq reads (`openByMasterEntity`) cannot serve that:

- it answers open tickets only;
- a ticket has no reference number at all;
- the assignee is a bare user id.

## What changes

- `TicketLeafProvider::byMasterEntity(refs, status)` takes a status filter: `open` (as `openByMasterEntity`, which now
  delegates to it), `closed` or `all`. An unknown filter answers 400.
- Every leaf row also carries `reference`, `assigneeName` (the user's display name, else the uid) and `resolvedAt`.
- The ticket schema gets an optional `reference` (1.4.0 → 1.5.0). `TicketReferenceCreatingListener` stamps `PQ-<year>-<nnnn>`
  on every new request and complaint before it is stored, unless it arrives with one. `TicketReferenceSequence` allocates
  the number race-safe on its own yearly counter. It extends `InvoiceSequenceService`, whose counter keys and format are
  now overridable; invoice numbers are unchanged.

## Scope

pipelinq only. dossiq reads `byMasterEntity` with `all` for its Tickets card, and shows the reference and the assignee's
name.
