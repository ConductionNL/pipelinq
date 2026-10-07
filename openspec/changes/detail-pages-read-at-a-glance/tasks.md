# Tasks: detail-pages-read-at-a-glance

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
