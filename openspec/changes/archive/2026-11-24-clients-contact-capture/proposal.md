---
kind: code
depends_on: []
---

# Proposal: clients-contact-capture

## Summary

Paste an email signature, or take a photo of a business card, and get a new
contact form with the fields already filled in. You check the fields and save.
Today every contact is typed by hand, imported from a vCard, or looked up in KvK
or the BRP.

## Motivation

Two rows of the pipelinq capability matrix (`openspec/parity/capabilities.json`,
compared 2026-09-24), both decided `build` by the OpenSpec pass of 2026-09-27.
Both are in the core area of the matrix (clients).

**`clients-smart-paste`**, "Paste an email signature and have the contact fields
filled in for you". Rated no, built.state none. Matrix evidence: "no signature
or free text parser in lib/Service or src/ (grep for paste, parseSignature);
contact data comes in by form, vCard import (lib/Service/ContactImportService.php:64)
or KvK and BRP lookups". Demand: changelog,
https://docs.espocrm.com/extensions/intelligence/. Competitors: pipedrive,
espocrm and odoo-crm partial, hubspot-crm unknown.

**`clients-business-card-scan`**, "Create a contact by scanning a business card
with your phone". Rated no, built.state none. Matrix evidence: "no camera, OCR
or card scan anywhere in src/ or lib/, and no mobile client (plat-phone partial:
openspec/specs/time-entry-mobile only)". Demand: changelog,
https://www.pipedrive.com/en/product-updates. One competitor rates it yes:

- pipedrive: https://support.pipedrive.com/en/article/card-scanner-mobile, "The
  scanner recognizes information on your cards and can scan multiple phone
  numbers or email addresses at once", reviewed before saving.

A scanned card becomes text, and that text goes through the same field parser
as a pasted signature, so both rows share one change.

## What changes

- The Clients and Contacts lists get a Paste or scan action next to Add.
- A dialog takes pasted text, or a photo from the phone camera or a file.
- A photo becomes text through Nextcloud's optical character recognition task
  (`core:image2text:ocr`) when a provider is installed.
- pipelinq splits the text into proposed fields: name, organisation, job title,
  email, phone, website, address and KvK number.
- The ordinary create form opens with those fields filled in. Nothing is saved
  until the user presses Save.

## Out of scope

- A native mobile app. The dialog works in the phone's browser and opens the
  camera through the file input.
- Keeping the card image. It is sent for recognition and then discarded unless
  the user attaches it to the new contact themselves.
- Reading a whole mailbox for new contacts (`mail-client-plugin` is its own row).

## Impact

- New `src/modals/ContactCaptureModal.vue`, registered in `src/registry.js`, and
  a header action on the Clients and Contacts pages in `src/manifest.json`.
- New `lib/Service/ContactCaptureService.php` and one route,
  `POST /api/contact-capture/parse`.
- Saving reuses `createWithContact` and `POST /api/contacts-sync/create`.
