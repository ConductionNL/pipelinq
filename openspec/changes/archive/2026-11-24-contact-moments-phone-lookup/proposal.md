---
kind: code
depends_on: []
---

# Proposal: contact-moments-phone-lookup

## Summary

When a number calls that matches no known contact, the agent still sees the
earlier calls and contact moments from that number. And a search on the Tickets
list for a phone number finds the ticket however the number was typed: with
spaces, dashes, a leading zero or +31. The screen pop already matches numbers
this way for contacts and clients; tickets and unknown callers do not get it.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), both decided `build` by the OpenSpec pass of 2026-09-27.

**`cm-history-by-phone`**, "See earlier contacts from a phone number even when
you cannot tell who the caller is". Rated partial, built.state built. Matrix
evidence: "every CTI call stores the caller's number in E.164 on its contact
moment (lib/Service/CtiService.php:272, declared on ticket in
lib/Settings/register.d/70-cti.json:42); the screen pop only matches the number
to contacts and clients (lib/Service/CtiContactMatcher.php:88 findByPhoneNumber),
and no page lists earlier contact moments for a number that matches nobody".
Note: "the data is there, the view for an unknown caller is not". Demand:
feature request, https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1509.
Competitors: espocrm, odoo-crm and kiss partial; hubspot-crm and pipedrive
unknown.

**`req-phone-format`**, "Find a request by phone number however the number was
typed". Rated partial, built.state built. Matrix evidence:
"lib/Service/CtiContactMatcher.php:132 needles() matches on E.164, digits only
and the last 8 or 9 digits, so a caller is found however the number was stored,
but only for contacts and clients during a screen pop; CTI tickets store the
number normalised (CtiService.php:265). Searching the Tickets list for a request
by number is a plain text search on whatever was typed". Demand: feature
request, https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1538.
One competitor rates it yes:

- odoo-crm: source read, `addons/phone_validation/models/mail_thread_phone.py:47`
  `phone_mobile_search` strips separators with a `regexp_replace` index, so a
  number typed with spaces or dashes is found.

Both rows need one thing: a number search over tickets that normalises the way
the screen pop already does. One change serves both.

## What changes

- A phone search over tickets that normalises the query with pipelinq's
  `PhoneNormaliser` and matches the same fragments as the screen pop.
- The screen pop for an unknown caller lists earlier contact moments and tickets
  from that number, newest first, with a link to each.
- The Tickets list recognises a phone number typed in its search box and runs
  the phone search, showing that it did.
- Manually logged contact moments and web enquiries get their phone number
  stored normalised, so they are found too.

## Out of scope

- Matching numbers written inside free text (a description or a note).
- Merging an unknown caller's history into a contact once they are identified;
  linking a ticket to a contact stays a manual act.

## Impact

- New `lib/Service/PhoneHistoryService.php` and route `GET /api/tickets/by-phone`.
- `CtiContactMatcher::needles()` becomes a shared, public helper.
- `src/modals/NewContactIntakeModal.vue` (earlier contacts list) and the Tickets
  page search.
