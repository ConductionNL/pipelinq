---
kind: code
depends_on: []
---

# Learn the product and find a partner to set it up, from one Help page inside the app

## Why

Two rows share one screen. **vendor-training** ("Learn the product from courses the vendor runs") is rated `yes` by HubSpot, Pipedrive and Odoo, and **local-partner** ("Find somebody near you who will set it up for you") by HubSpot, Pipedrive, Odoo and KISS. Both are buying reasons in the matrix notes ("HubSpot Academy is a real reason buyers pick HubSpot"). We have written docs (`docs/`) and a walkthrough block in `src/manifest.json`, and `appinfo/info.xml` names one vendor; there is no place in the app that says where to learn or who to call. The courses and the partner network themselves are Conduction's business to produce, not code. What the app can do is give them a home: a Help page that lists what an administrator configures, so the app is ready the day a course or a partner exists. Nothing is invented: with nothing configured, the page shows the docs and the walkthrough only.

This change covers 2 row(s) of the parity matrix (`openspec/parity/capabilities.json`), decided `build` on 2026-09-28.

**vendor-training** (matrix `pipelinq`, area `platform`), "Learn the product from courses the vendor runs" Rated `partial` for us, `built.state` `building`.
- Demand: None. No demand row; the row comes from our own code or our own matrix.
- HubSpot CRM, yes: https://academy.hubspot.com/courses "Creating a HubSpot Academy account is 100% free and gets you unlimited access to our complete library of education", with self paced courses and certifications.
- Pipedrive, yes: https://learn.pipedrive.com/app "CRM Training Courses and Tutorials | Pipedrive Academy ... Learn for free", with courses and webinars (https://support.pipedrive.com/en/article/live-on-demand-webinars).
- EspoCRM, partial: https://www.espocrm.com/video/ "EspoCRM Video Tutorials" (Accounts, Leads, Reports and Analytics, Workflow Management, BPM and others) plus docs at https://docs.espocrm.com/ and paid consulting and training on request (https://www.espocrm.com/support/ service option "Consulting & Training"); no structured course or certification
- Odoo CRM, yes: https://www.odoo.com/slides lists free vendor courses, "CRM Odoo Tutorials 2h 40m 22 steps" among 101 courses, next to the paid certification programme under Learn > Certifications.
- Matrix note: HubSpot Academy is a real reason buyers pick HubSpot. Written docs are not the same thing.

**local-partner** (matrix `pipelinq`, area `platform`), "Find somebody near you who will set it up for you" Rated `partial` for us, `built.state` `building`.
- Demand: None. No demand row; the row comes from our own code or our own matrix.
- HubSpot CRM, yes: https://ecosystem.hubspot.com/marketplace/solutions "Browse the directory of partners or get targeted recommendations through our questionnaire ... Connect with HubSpot partners who can help with any business need", filtered by service and tier; the directory is also served in Dutch.
- Pipedrive, yes: https://www.pipedrive.com/en/marketplace/partners "Explore top-rated partners in your region to help you unlock Pipedrive's full potential. Find an expert" with Region and Country filters. Note: Was partial; the expert directory filters by country.
- EspoCRM, partial: No partner directory on espocrm.com (https://www.espocrm.com/partners/ answered 404 on 2026-09-26); the vendor sells implementation services itself (https://www.espocrm.com/services/) and points buyers to community developers through forum signatures (https://www.espocrm.com/community-collaboration/ "help people who are seeking 
- Odoo CRM, yes: https://www.odoo.com/partners is the vendor's partner directory, filterable by country (https://www.odoo.com/partners/country/netherlands-166) with partner level, certified experts and customer references per partner.
- KISS, yes: AUTHORS.md:2 "Created by [ICATT](https://www.icatt.nl) for [Dimpact](https://www.dimpact.nl/) and [Gemeente Utrecht]"; ICATT (https://www.icatt.nl, "common ground-componenten te bouwen, zoals KISS (Podium D)") implements it and Dimpact supplies it to member municipalities within PodiumD

## What changes

- A "Help" page in the menu with three parts: Learn, Find a partner, Documentation.
- Learn lists courses an administrator has added (title, level, language, link) and offers "Replay the walkthrough".
- Find a partner lists partners an administrator has added (name, province, services, website, contact email) and filters by province and service. Conduction is the default entry.
- Administration gets one section to add, edit, reorder and remove courses and partners.
- Empty sections are hidden, not shown as empty tables.

## Capabilities

### Modified capabilities
- `onboarding`: adds the Help page, the course list, the partner directory and their administration.
