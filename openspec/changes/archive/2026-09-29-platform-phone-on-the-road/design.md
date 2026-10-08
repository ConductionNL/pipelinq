# Design: platform-phone-on-the-road

## Context (read at pipelinq development ec6b0277)

- `src/views/MyWork.vue` lists leads and requests with filter buttons. `src/components/CtiClickToDialButton.vue` already renders a `tel:` link for the desk phone flow; `ContactChannelsSection.vue` and `ProspectWidget.vue` contain `tel:` too, but client and lead detail pages built from `src/manifest.json` do not link numbers.
- A logged contact moment is a ticket with `ticketType: interaction`, `direction: outbound`, `channel` a free string (`99-unify-ticket-supertype.json`); `ContactmomentController` and `ContactMomentFilingService` create them.
- Layout: `src/manifest.json` pages are rendered by nextcloud-vue; a phone uses the Nextcloud mobile shell or a mobile browser, both at narrow widths.
- No service worker, `.webmanifest` or IndexedDB exists in `src/`.

## Decisions

### D1. Responsive web, not a client

The change is CSS and components. It does not add a PWA shell, a service worker or a native app. Installing to the home screen is left to the Nextcloud mobile apps.

### D2. Links by a shared component

`ContactLinks.vue` renders the phone, mail and postal address of a record as `tel:`, `mailto:` and `geo:` links (RFC 5870, which a phone hands to its map app), with the helpers in `src/services/contactLinks.js`. Numbers are normalised for the link only; the visible text is unchanged.

Amended at build (29 Sep, code at HEAD): the detail pages render their fields through the library's `type: "data"` widget, which takes no per-field cell widget (only slots), so a cell widget registered in `src/App.vue` would never be reached on a detail page. `ContactLinks` is instead a registry section placed `before-body` on ClientDetail (phone, email, address) and ContactDetail (phone, email), so the links are the first thing on the page on a phone. A lead holds no number or address of its own, so the lead page gets "Log a visit" and no links; the spec requirement says client and contact pages accordingly.

### D3. The visit note reuses the contact moment path

`LogVisitAction.vue` (a registry section on ClientDetail and LeadDetail) opens `src/dialogs/LogVisitDialog.vue` (ADR-004: modals in their own file) and writes through the object store, as `ContactmomentQuickLog` does: a `ticket` with `ticketType: interaction`, `channel: visit`, `direction: outbound`, the client and lead references and the note, and, when a day is picked, a `crmTask` of type `followUpTask` due that day at 09:00. Payloads come from `src/services/visitLog.js` and are validated in `tests/vitest/visitLog.spec.js` against the merged `ticket` and `crmTask` schemas in `lib/Settings`. When the visit saves and the task does not, the dialog closes and says so, so a retry cannot log the visit twice. No new schema, endpoint or field.

My work (amended at build): the page listed only leads and requests; the follow-ups the spec names are `crmTask` records, so it now also lists the tasks assigned to the user, and a "Today" group (`src/services/myWorkGroups.js`) comes first.

### D4. Test at 390 px

Playwright runs the phone layout at a 390 by 844 viewport and asserts no horizontal overflow on client detail, lead detail, My work and the board. That is the only honest check of "works on a phone" available without a device.

## Declarative-vs-imperative decision

Layout and links are components and CSS; the visit note is data written through the existing path.

