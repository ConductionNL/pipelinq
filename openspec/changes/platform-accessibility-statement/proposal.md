---
kind: code
depends_on: []
---

# Proposal: platform-accessibility-statement

## Summary

Publish what pipelinq's back office does and does not meet under WCAG 2.1 AA,
screen by screen, with the evidence behind it. Give every municipality a
draft toegankelijkheidsverklaring it can complete and publish. Today the only
accessibility test covers the resident portal, and no statement exists
anywhere. This change adds the audit evidence for the back office, the
conformance report, the draft statement, and a link to both from the admin
page.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`plat-wcag`**, "Use every screen with a keyboard and a screen reader, to WCAG
2.1 AA, with a published accessibility statement". Rated partial, built.state
built. Matrix evidence: "pages are built on Nextcloud Vue components;
tests/e2e/portal-accessibility.spec.ts:5 \"Customer-portal WCAG 2.2 AA
structural accessibility checks\" (keyboard, landmarks, live regions) covers the
portal only, and says the axe-core sweep is not run; no accessibility statement
(toegankelijkheidsverklaring) anywhere in docs/ or the app". Note: "no
published accessibility statement and no audit evidence for the back office
screens".

The decision reason: partial and built, and the missing half is a published
accessibility statement and audit evidence for the back office screens. The
open change `keyboard-accessible-click-toggles` fixes four controls and does
not cover the statement. Demand: tender,
https://www.tenderned.nl/aankondigingen/overzicht/414691, with the requirements
"intelligence-db requirements#207295 (Gemeente Midden-Drenthe, 2026-03-05: EN
301 549 met WCAG 2.1 niveau AA)" and "intelligence-db requirements#204185
(Gemeente Noordwijk, 2026-04-08: toegankelijkheidsverklaring)".

Competitor cells, quoted from the matrix:

- hubspot-crm (partial): https://legal.hubspot.com/website-accessibility covers
  HubSpot's public websites against WCAG 2.1 AA; a product VPAT exists as an
  older PDF, "date and scope not confirmed. No current statement for the app
  itself was found."
- odoo-crm (partial): "Odoo publishes no accessibility statement or WCAG
  conformance claim [...]; the web client uses aria attributes and keyboard
  shortcuts".
- kiss (partial): "accessibility work shipped in v1.2.0 [...] and the repo holds
  a 2023 test report (docs/files/WCAG-Lighthouse-Report-20231010.pdf); no
  published accessibility statement found, and open bug
  https://github.com/Klantinteractie-Servicesysteem/KISS-frontend/issues/1131".
- pipedrive (unknown): "no accessibility statement or VPAT found;
  https://www.pipedrive.com/en/accessibility answered 404".
- espocrm (unknown): "no accessibility statement exists
  (https://www.espocrm.com/accessibility/ answered 404 on 2026-09-26)".

## What changes

- A Playwright suite runs axe-core over a fixed sample of back office screens
  and fails on any WCAG 2.1 A or AA violation that is not listed as a known
  issue.
- A keyboard-only walk goes through the same sample: every control reachable
  with Tab, focus visible, dialogs closed with Escape.
- A manual audit of the sample with a screen reader, following WCAG-EM, is
  written down per criterion.
- An Accessibility Conformance Report per release lists every WCAG 2.1 AA
  success criterion as supported, partially supported, not supported or not
  applicable, and names the known issues with the change that fixes each.
- A draft toegankelijkheidsverklaring in Dutch, pre-filled with what pipelinq
  can say, for the municipality to complete and publish.
- The Nextcloud admin page for pipelinq links to both, with the date of the
  last audit.

## Out of scope

- Fixing the four controls of `keyboard-accessible-click-toggles`. That change
  fixes them; this one lists them as known issues until it lands.
- Publishing the statement for a municipality. The legal statement belongs to
  the organisation that offers the service; pipelinq supplies the evidence and
  the draft.
- The resident portal's statement. The bespoke portal is due to retire in
  favour of portaliq (hydra ADR-046); portaliq carries that link.
- Fixes found by the audit. Each becomes its own change, named in the report.

## Impact

- New e2e specs `tests/e2e/backoffice-axe.spec.ts` and
  `tests/e2e/backoffice-keyboard.spec.ts`, using the `axe-core` package that
  `package.json` already lists and nothing imports.
- New docs: `docs/compliance/accessibility-conformance-report.md`,
  `docs/compliance/toegankelijkheidsverklaring-concept.md`, and the audit
  record `docs/compliance/wcag-audit-<date>.md`.
- `src/views/settings/Settings.vue`: one section with the two links.
- `docs/Features/government-compliance.md` rows A-01 to A-04 point at the
  report instead of saying "Gepland".
