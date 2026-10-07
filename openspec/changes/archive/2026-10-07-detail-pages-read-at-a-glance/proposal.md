---
kind: code
depends_on: [pipeline-numbers-tell-the-truth]
---

# Proposal: detail-pages-read-at-a-glance

## Summary

The contact, lead and ticket pages show what matters above the fold, and each
block has a real title. You take a ticket with one action, "Assign to me", on
the ticket and on the Queue. My Work shows every ticket assigned to you, not
only requests.

## Motivation

Ruben's review of the pipelinq beta on 2026-10-06 (items E2, E3, G1).

- **E2.** The contact page showed "Open deals" twice: one stats block with a
  count and an unformatted amount. Below the grid sat a long unstyled tail of
  body sections: relationships, contact channels, messages, subscriptions and
  the BSN lookup.
- **E3.** The lead page had two data widgets, both titled "Data", because the
  library reads a data widget's title from `content.title` only. The contact,
  client and ticket data widgets had the same bug.
- **G1.** Ruben asked how he assigns a ticket so it lands in his work queue.
  A ticket's `assignee` could only be set by editing the field inline or by a
  routing suggestion. My Work and the dashboard worklist only loaded tickets
  of type request, so an assigned complaint or contact moment never showed.

## What changes

- `sectionWidget()` turns a self-fetching body section into a grid widget.
  The contact page's sections become widget types in two tab strips: Profile
  (relationships, subscriptions, BSN and BRP) and Communication (channels,
  mail, messages, meetings, files). Nothing renders below the grid.
- One "Open deals" KPI: the open deal value in the reporting currency, with
  the count as its caption, from `GET /api/analytics/open-deals?contact=`
  (or `client=`), read under the user's RBAC.
- The lead page has one "Deal" data widget with every field, the win chance
  among them. The deal value KPI formats in the reporting currency.
- Data widgets carry their title in `content.title` on the contact, client,
  lead and ticket pages.
- `POST /api/tickets/{id}/assign-to-me` sets the assignee to the signed-in
  user, under their RBAC. TicketDetail has it in its Actions menu; each Queue
  row has it too, and the ticket then opens.
- My Work and the worklist endpoint load every ticket type assigned to you,
  with a badge per type, and skip closed tickets of every type.

## Out of scope

- Fixing `widgetTitleOf` in the library (the nextcloud-vue lane). The
  `content.title` keys work with the current and the fixed library.
