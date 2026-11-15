---
kind: code
---

# Proposal: ticket-decisions-tab

## Summary

A ticket gets a Decisions tab that lists the formal decisions on it, including the ones taken on the case the ticket became, and offers the three ways forward the board draws: Create a proposal for this ticket, Convert to a case and Open in decidiq. This follows board PqTicketBesluitvorming.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`. Decision 99 (8 Oct 2026).

- **req-decisions** See the formal decisions on a request and prepare one from it (new, specified, partial).

## What exists, checked in decidiq first

The brief asked to check decidiq for a ticket-to-decision link before writing anything. decidiq owns it, and it is built:

- decidiq registers the leaf `decidesk-decisions` on both layers (`decidiq/lib/Listener/RegisterDecisionsLeafListener.php`, `decidiq/src/integrations/registerDecisionsLeaf.js`; spec `decidiq/openspec/specs/decidesk-contract-decision-hub` REQ-DCDH-008). Its widget lists decisions whose `subjectId` is the host object, offers "Create proposal for this object" (a decision pre-linked through `subjectRegister`, `subjectSchema`, `subjectId`, REQ-DCDH-001) and "Open in decidiq".
- decidiq's flow node `decidiq.request-decision` (archived change `2026-10-07-flow-request-decision-node`) raises a decision about any object a flow carries.
- pipelinq mounts the leaf on the ticket page as widget `ticket-decisions` (`src/manifest.json:2512`), full width under the data block, and the ticket schema carries `decisions` (`lib/Settings/register.d/99-unify-ticket-supertype.json:262`).

So this change writes nothing in decidiq. Two things on the board are missing in pipelinq:

1. **The tab.** The board shows the decisions as a tab beside Overview, Contact moments and Related, with the text "Formal decisions on this ticket, made and signed in decidiq". Today they sit at the bottom of the overview.
2. **Decisions on the case.** The board's empty state says "A decision can be prepared straight from this ticket as a proposal in decidiq, or the request becomes a case in dossiq first. Either way the decision shows here." The leaf only lists decisions whose subject is the ticket. A decision on the dossiq case the ticket became (`caseReference`) does not show.

## What changes

1. The ticket page gets a Decisions tab that mounts the `decidesk-decisions` leaf for the ticket, with the board's heading and empty-state text, and the actions Create a proposal for this ticket (the leaf's create), Convert to a case (the existing convert flow of req-to-case) and Open in decidiq.
2. When the ticket has a `caseReference`, the tab also lists the decisions whose subject is that case, under the case's title, read through OpenRegister (ADR-022).
3. The widget at the bottom of the overview goes, so the decisions show in one place.
4. Without decidiq the tab is hidden, as the leaf is today.

## Out of scope

- How decidiq makes and signs a decision.
- The other ticket tabs (Contact moments, Related). Their rows are built; their placement follows the ticket page layout.
