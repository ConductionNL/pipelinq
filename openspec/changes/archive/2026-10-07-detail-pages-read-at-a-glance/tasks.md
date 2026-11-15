# Tasks: detail-pages-read-at-a-glance

> Archive pass 2026-10-07: code done (#2178), every task ticked; not archived. The main spec `openspec/specs/my-work/spec.md` holds the whole document twice (two `# My Work` titles, three `## Requirements` headers, eight requirement names repeated), so `openspec archive` refuses to write into it. Its delta headers were corrected from MODIFIED to ADDED (none of REQ-DPG-* existed in a main spec). Archive after my-work is collapsed to one copy.
>
> Delta fix-up 2026-10-07: `specs/my-work/spec.md` collapsed to its first copy, the one #1754 maintained and the code follows (leads, tickets and follow-ups; no KPI tiles, quick actions or activity feed). Archived.

## 1. Contact page (E2)

- [x] 1.1 Add `src/components/widgets/sectionWidget.js` and register the contact sections as widget types in `src/registry.js`
- [x] 1.2 Add `OpenDealsController` and `GET /api/analytics/open-deals`
  - Verify: `OpenDealsControllerTest` (sum, 404 on an unreadable party, exactly one party)
- [x] 1.3 ContactDetail: one Open deals stat, Profile and Communication tab strips, no `placement: end` body sections
  - Verify: live, the KPI reads the open value in EUR with its count; every tab renders its section

## 2. Lead page and titles (E3)

- [x] 2.1 One "Deal" data widget on LeadDetail; deal value as a currency stat
- [x] 2.2 `content.title` on the contact, client, lead and ticket data widgets
  - Verify: live, the blocks read "Contact details", "Deal" and "Ticket details"

## 3. Assign to me and My Work (G1)

- [x] 3.1 Add `TicketAssignController` and `POST /api/tickets/{id}/assign-to-me`
  - Verify: `TicketAssignControllerTest` (assigns, 404, 403, 401)
- [x] 3.2 Header action on TicketDetail; row action on the Queue through `src/services/ticketAssign.js`
  - Verify: `tests/vitest/ticketAssign.spec.js`; live, the Queue row action assigns the ticket and opens it
- [x] 3.3 My Work and `WorklistService` load every assigned ticket type
  - Verify: `WorklistServiceTest::testEveryAssignedTicketTypeIsWork` fails on the old code; live, an assigned complaint shows on My Work

## 4. Text

- [x] 4.1 English and Dutch strings, `npm run l10n:build`
  - Verify: `npm run test:l10n` exit 0
