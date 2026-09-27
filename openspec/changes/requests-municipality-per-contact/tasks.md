# Tasks: requests-municipality-per-contact

## 1. Schema

- [ ] 1.1 Add `lib/Settings/register.d/` fragment with the `municipality` schema (`name`, `code`, `portalTenantId`, `organisation`) and a `municipality` `$ref` property, facetable, on `client`, `contact` and `ticket`
  - Verify: `npm run check:schema-l10n` exit 0; PHPUnit `tests/Unit/Settings/RegisterAnnotationsTest.php` finds the three properties and the new schema
- [ ] 1.2 Add a Municipalities index page and place it in the settings section in `src/menu-layout.json`
  - Verify: `npm run check:manifest` exit 0; Playwright adds two municipalities on the page

## 2. Stamping

- [ ] 2.1 In `PortalRequestService::submit()`, write the municipality whose `portalTenantId` matches the tenant
  - Verify: PHPUnit: a tenant with a municipality stamps it; a tenant without one leaves the field empty
- [ ] 2.2 In `TicketService::save()`, copy the client's municipality onto a new ticket when none is given
  - Verify: PHPUnit: create with a client copies; create with an explicit municipality keeps it; an update does not copy

## 3. Lists and back-fill

- [ ] 3.1 Add a Municipality column and facet to Clients, Contacts, Tickets and Queue in `src/manifest.json`
  - Verify: Playwright `tests/e2e/municipality-per-contact.spec.ts` reads the municipality name (not a uuid) in the Clients column, filters Tickets to one municipality and sees only its tickets
- [ ] 3.2 Add a repair step that copies each client's municipality onto its existing tickets that have none
  - Verify: PHPUnit runs it twice and changes each ticket once

## 4. Text and docs

- [ ] 4.1 English strings and Dutch translations, sentence case, no em-dashes
  - Verify: `npm run test:l10n` exit 0
- [ ] 4.2 Admin doc `docs/Features/kcc-werkplek.md`: a section on serving several municipalities, and when to use one organisation per municipality instead
  - Verify: docs build exit 0
