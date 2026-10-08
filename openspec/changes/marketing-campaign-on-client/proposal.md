---
kind: code
depends_on: []
---

# Proposal: marketing-campaign-on-client

## Summary

Open a client or a contact and see how they found you: the campaign, the
source and medium, and the landing page of their first and latest web form
submission, and every submission in a list. The website enquiry form keeps the
same campaign values as a landing page form does. Today those values stop at
the lead and at a touchpoint log nobody can open.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), decided `build` by the OpenSpec pass of 2026-09-27.

**`mkt-form-campaign-tags`**, "Keep the campaign tags and landing page of every
web form submission on the client record". Rated partial, built.state built.
Matrix evidence: "a portaliq landing-page submission becomes a contact, a lead
with campaignId and firstTouch/lastTouch, and a touchpoint carrying the campaign
(lib/Listener/LandingPageFormSubmittedListener.php:6 'Turns a visitor's
landing-page form submission into a contact, a lead', registered at
lib/AppInfo/Application.php:285; lead fields in
lib/Settings/register.d/98-marketing-campaigns.json:392); nothing puts the
campaign or landing page on the client record, no page shows touchpoints, and
the website enquiry form (lib/Service/EnquiryIntakeService.php) keeps no UTM
values". Note: "kept on the lead and in the attribution log, only for portaliq
landing pages". Demand: changelog, https://www.pipedrive.com/en/product-updates.
One competitor rates it yes:

- odoo-crm: source read, `addons/utm/models/utm_mixin.py:26-39` `default_get`
  reads the campaign, medium and source cookies set from the landing URL, so a
  website form lead keeps them (`website_crm/models/crm_lead.py:51`).

hubspot-crm, pipedrive and espocrm rate it partial. The missing half is the
client and contact record, the touchpoint list, and the enquiry form.

## What changes

- Contacts and clients carry a first touch and a last touch: campaign, source,
  medium and landing page, with the date.
- A landing page submission and a website enquiry both write a touchpoint with
  the contact, the client and the landing page, and update those touches.
- ContactDetail and ClientDetail show the two touches and a Touchpoints list.
- The website enquiry form accepts the campaign values and the page it was sent
  from.

## Out of scope

- Reading cookies or tracking visitors in pipelinq. The campaign values arrive
  with the submission: portaliq already records first and last touch
  (portaliq matrix `ana-campaign-utm`), and the enquiry embed passes the values
  of the page it sits on.
- Changing how a lead is attributed to a campaign (`CampaignAttributionService`).

## Impact

- `register.d/98-marketing-campaigns.json`: `touchpoint` gains `clientId` and
  `landingPage`; a fragment adds `firstTouch` and `lastTouch` to `contact` and
  `client`.
- `lib/Listener/LandingPageFormSubmittedListener.php`,
  `lib/Service/EnquiryIntakeService.php`, `lib/Service/TouchpointService.php`.
- `src/manifest.json`: ContactDetail and ClientDetail widgets.
