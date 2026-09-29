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

`ContactLink.vue` renders a value as `tel:`, `mailto:` or a `geo:`/maps link by type, and is used where the manifest detail pages show these fields through a cell widget registered like `lead-probability` in `src/App.vue`. Numbers are normalised for the link only; the visible text is unchanged.

### D3. The visit note reuses the contact moment path

`LogVisitSheet.vue` posts to the existing contact moment endpoint with `channel: visit`, `direction: outbound`, the client or lead reference, the note, and an optional follow-up date that creates the existing follow-up task. No new schema, endpoint or field.

### D4. Test at 390 px

Playwright runs the phone layout at a 390 by 844 viewport and asserts no horizontal overflow on client detail, lead detail, My work and the board. That is the only honest check of "works on a phone" available without a device.

## Declarative-vs-imperative decision

Layout and links are components and CSS; the visit note is data written through the existing path.

