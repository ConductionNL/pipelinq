# Design: marketing-campaign-on-client

## Context (read at pipelinq development 418bdc4ad)

- **Landing page submissions.** `lib/Listener/LandingPageFormSubmittedListener.php::ingest()`
  resolves the campaign (`campaignIdFor()`, from the external reference
  `pipelinq:campaign:<id>` or the campaign's `formRef`), matches or creates a
  contact by email, creates a lead, and appends a touchpoint through
  `TouchpointService::append()`. `lib/Event/LandingPageFormSubmittedEvent.php`
  carries `getPageRoute()`, `getPortal()`, `getUtmFirstTouch()` and
  `getUtmLastTouch()`.
- **Schemas.** `lib/Settings/register.d/98-marketing-campaigns.json`: `touchpoint`
  has `campaignId, contactId, leadId, kind, channel, utm (campaign, source,
  medium, ...), sourceRef, occurredAt, nonce, createdAt`; `lead` gains
  `firstTouch`, `lastTouch` (objects of utm values) and `campaignId`. `contact`
  and `client` carry none of it.
- **Enquiry form.** `lib/Service/EnquiryIntakeService.php::submit()` (:134)
  keeps only `SUBMITTER_FIELDS` (`title, contactName, contactEmail,
  contactPhone, organisation, message, source, pageUrl, locale`) and sets
  `source`, `status`, `receivedAt` and `title` server side. Route
  `POST /api/enquiry` (`appinfo/routes.php:197`), anonymous.

## Decisions

### D1. The touchpoint is the record; the touches are summaries

Every submission stays one `touchpoint`. It gains `clientId` (resolved from the
contact's `client` when the contact has one) and `landingPage` (portal plus
page route for a landing page, `pageUrl` for an enquiry). `contact` and
`client` gain `firstTouch` and `lastTouch` with the same object shape as the
lead's, plus `campaignId`, `landingPage` and `at`. `firstTouch` is written once
and never overwritten; `lastTouch` is replaced on every submission. They are
summaries so a list can filter on them; the history is the touchpoint list.

### D2. One writer for both intakes

`TouchpointService::recordSubmission(contactId, campaignId, utm, landingPage,
channel)` appends the touchpoint and updates the contact's and its client's
touches. The landing page listener calls it instead of `append()`, and
`EnquiryIntakeService` calls it after storing the enquiry when a contact is
matched by the enquiry's email. An enquiry without a matching contact keeps its
values on the enquiry and on its touchpoint; the touches are written when the
enquiry is converted to a contact.

### D3. The enquiry form accepts campaign values, validated

`SUBMITTER_FIELDS` gains `utmCampaign`, `utmSource`, `utmMedium`,
`utmContent`, `utmTerm`, each trimmed and capped at 100 characters, and
`pageUrl` is kept as the landing page. The embed snippet in the docs fills them
from the page's own query string. These values describe the visit; they never
set `source` or `status`, which stay server owned.

### D4. Screens

ContactDetail and ClientDetail get a How they found us widget (first and last
touch) and a Touchpoints object list (date, campaign, channel, source, medium,
landing page), both read only.

## Risks

- UTM values are visitor-supplied text. D3 caps and trims them, and the widgets
  render them as text.
- A client reached through many contacts collects many touchpoints; the list is
  paged and newest first.
