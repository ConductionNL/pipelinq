---
kind: code
depends_on: []
---

# Work a client visit from a phone: today's list, call and map links, and a note in three taps

## Why

HubSpot, Pipedrive, EspoCRM and Odoo all rate `yes` for working from a phone; we rate `partial`. Nextcloud already ships native apps that open Pipelinq in a web view, so a second native client is not the answer and is not proposed. What fails on a phone today is the web app itself: there is no phone-first "today" list, phone numbers and addresses are not links, and logging what happened at a visit takes a full form. The archived and main spec `time-entry-mobile` describes an offline PWA timer that was never built and is a different capability (its front matter now says not implemented); it is untouched here, and offline work is a separate row decided `decided-no` in this pass.

This change covers 1 row(s) of the parity matrix (`openspec/parity/capabilities.json`), decided `build` on 2026-09-28.

**plat-phone** (matrix `pipelinq`, area `platform`), "Work from your phone while you are with a client" Rated `partial` for us, `built.state` `building`.
- Demand: None. No demand row; the row comes from our own code or our own matrix.
- HubSpot CRM, yes: https://knowledge.hubspot.com/account-mangagement/how-to-install-the-hubspot-mobile-application "view and edit records, make calls, manage marketing emails, and use the conversations inbox directly from your mobile device" on iOS 17 and Android 11 and up. All products and plans.
- Pipedrive, yes: https://www.pipedrive.com/en/features/mobile-crm "all-in-one mobile CRM app for iPhone and Android"; https://support.pipedrive.com/en/article/what-features-do-the-mobile-apps-have lists calling and call logging, and "Mobile Android and iOS apps" are on all plans (https://support.pipedrive.com/en/article/what-features-do-the-pipe
- EspoCRM, yes: The web client adapts to small screens (client/src/views/site/navbar.js:580 "const smallScreenWidth = this.getThemeManager().getParam('screenWidthXs')" collapses the navbar on phones), so it works in a phone browser; no official native mobile app is listed at https://www.espocrm.com/extensions/. Driven 2026-09-26 on the 10.0.8 l
- Odoo CRM, yes: https://www.odoo.com/documentation/19.0/applications/general/mobile.html "Two kind of Odoo mobile app exist: the progressive web app (PWA) and store apps" with push notifications, on Google Play and the App Store; the web client is responsive, so CRM, contacts and activities work from the phone in Community too. Note: The store 
- Matrix note: The four commercial and open source CRMs all ship a native mobile app. We do not.

## What changes

- The "My work" page gets a phone layout: one column, the day's calls and follow-ups first, large touch targets.
- Phone numbers on clients, contacts and leads are `tel:` links; addresses open the phone's map app; email addresses are `mailto:` links.
- A "Log a visit" quick action on a client or lead records an outbound contact moment with channel "visit", a one-line note and an optional follow-up date, in a single small sheet.
- Client detail, lead detail and the board reflow at phone width without horizontal scrolling.

## Capabilities

### Modified capabilities
- `mobile-experience`: new capability for the phone layout, link handling and the quick visit note.
