# Design: contact-moments-phone-lookup

## Context (read at pipelinq development cfe0a0a51)

- **Storage.** `lib/Settings/register.d/70-cti.json` adds `from_number` ("Caller
  phone number in E.164 format") and `to_number` to `ticket`.
  `CtiService::createPendingContactmoment()` writes `from_number` as E.164
  (`CtiService.php:265`, `phoneNormaliser->normaliseForOrg(...)['e164']`).
  `26-website-enquiry.json` gives `enquiry` a `contactPhone`, stored as typed.
- **Matching.** `CtiService::initiateScreenPop()` (:219) normalises the number
  and calls `CtiContactMatcher::findByPhoneNumber()` (:88), which tests
  `needles()` (:132): the E.164 value, digits only, and the last 9 and last 8
  digits, against contacts and clients only. With no match the action is
  `ACTION_INTAKE`, which opens `src/modals/NewContactIntakeModal.vue` with the
  `e164` prop.
- **Normalisation.** `lib/Service/PhoneNormaliser.php::normaliseForOrg()`
  returns `raw` and `e164` for an organisation's default country.

## Decisions

### D1. One number matcher for people and tickets

`needles()` moves to a public static `PhoneNeedles::for(e164)` used by
`CtiContactMatcher` and the new service, so contacts, clients and tickets are
matched by the same fragments.

### D2. Tickets carry a normalised number

`ticket` gains `phoneE164` (string, facetable, not shown in forms). CTI
contact moments set it from `from_number` (inbound) or `to_number`
(outbound); a manual contact moment or a ticket created from an enquiry sets it
from the number typed, through `PhoneNormaliser`. A repair step fills it for
existing tickets from `from_number`, idempotently.

### D3. `PhoneHistoryService::findTickets(raw, orgId)`

Normalises the query, then searches `ticket` on `phoneE164` for the E.164 value,
and falls back to the last-9-digits fragment for numbers stored before the
repair. Results are read as the viewer through OpenRegister's object service,
newest first, capped at 50. Exposed as `GET /api/tickets/by-phone?number=`,
`#[NoAdminRequired]`; each result is subject to the viewer's read rights, so no
extra per-object guard is needed beyond the object service's RBAC.

### D4. Two screens use it

- `NewContactIntakeModal` (unknown caller) shows Earlier contacts from this
  number from `findTickets(e164)`: date, type, subject, handler, with a link.
- The Tickets list: when the search text is a phone number (at least 6 digits
  once separators are removed and nothing but digits, spaces, dashes, dots,
  brackets and a leading plus), the page calls the phone search instead of the
  plain text search and shows "Searching by phone number" above the results.

## Risks

- Last-9-digit matches can hit a number from another country with the same tail.
  The E.164 match is tried first; tail matches are labelled as possible matches.
- The repair touches every ticket with a `from_number` once; it is idempotent and
  batched like `CtiContactMatcher::normaliseStoredPhoneNumbers()`.
