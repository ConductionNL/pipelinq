# A request's Termijn and Gekoppelde zaak cards can be acted on

## Why
Board PqTicket (and PqTicketBesluitvorming, Contactmomenten, Gerelateerd, Meer) draws two actions in a request's side column: "Termijn instellen" under the Termijn card and "Bestaande zaak koppelen" under "Nog geen zaak gekoppeld." The side cards are config since pq-b5/pl3-ticket-side-cards-as-board (a000bd26c), but neither action exists, and the Termijn card shows the deadline as a plain fact where the board writes it as a 26px figure.

Both values are fields the ticket already has: `slaDeadline` (documented as "complaint only", though the Termijn card reads it on requests) and `caseReference` (the case the request belongs to, which convert-to-case writes). Setting either one is a patch of the ticket under OpenRegister's object RBAC. No pipelinq endpoint is needed.

## What changes
- `ticket.slaDeadline` applies to every ticket type that has a term, requests included (schema description only; no data migration).
- The Termijn card offers "Termijn instellen": a small form with one date-time field (`slaDeadline`), saved as a patch of the ticket. A user who may not update the ticket does not see it.
- The Gekoppelde zaak card, while `caseReference` is empty, offers "Bestaande zaak koppelen": a search over the cases the case app (dossiq) lists, saved as `caseReference` on the ticket. Linking does not change the ticket's status; converting stays the way to create a case.
- The Termijn card writes the deadline as the card's figure (26px, the board's size), with Prioriteit and Behandelaar under it.

## What does not change
- Convert-to-case and its handoff endpoint.
- Complaint SLA timers: the escalation engine keeps reading `slaDeadline` as before.

## Shared parts
- nc-vue: a facts card can carry one footer action that runs a declared header action (patch form or object search), and a facts row can be drawn `as: "title"` with a size. Both belong in nc-vue, not in pipelinq; pipelinq only declares them.
