---
kind: code
depends_on: []
---

# Proposal: work-letter-from-filinq-template

## Summary

Make a letter for one client from a template, filled with the client's
details, as a PDF you can print or send. filinq renders it; pipelinq picks the
template, hands over the client and the ticket, and files the result. The
letter shows on the client's timeline as an outgoing contact moment. Today
pipelinq fills templates for mail and Berichtenbox messages only, and no
printable document exists for a client.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`work-letter-template`**, "Produce a letter from a template filled with the
client's details". Rated partial, built.state built. Matrix evidence:
"lib/Service/TemplateRenderer.php:77 \"render(array $template, array
$variables)\" fills {{var}} placeholders for mail templates (Templates page,
src/manifest.d/75-marketing-blasts.json:16) and
lib/Settings/register.d/85-berichtenbox-templates.json holds Berichtenbox
message templates; no letter or document (PDF, docx) is produced for one
client". Note: "templates exist for mail and Berichtenbox, not for a printable
letter".

The decision reason: partial and built, and the missing half is a printable
letter for one client. filinq generates documents for other apps (filinq
matrix rows `con-gen-for-apps` and `gen-from-record`), so this change is the
pipelinq side of that call, as dossiq did for decisions. Demand: tender,
https://www.tenderned.nl/aankondigingen/overzicht/414691, with the
requirements "intelligence-db requirements#199364 (Gemeente Midden-Drenthe,
2026-03-05: documentsjabloon voor een afspraakbrief aan een processtap
koppelen)", "intelligence-db requirements#174086 (Kadaster, 2025-07-19: brieven
automatisch uit sjablonen, aanhef naar gender)" and "intelligence-db
requirements#55537 (NZa, 2024-12-06: sjabloon voor e-mail en brief)". Also
seen in the EspoCRM changelog, https://github.com/espocrm/espocrm/issues/3417.

Competitor cells, quoted from the matrix:

- espocrm (yes): "application/Espo/Resources/metadata/entityDefs/Template.json
  fields body, header, footer, entityType, pageFormat, filename edited at
  Administration > Template Manager (adminPanel.json:168);
  client/src/views/record/detail.ts:1003 printPdfAction adds Print to PDF on
  the record, rendered by application/Espo/Tools/Pdf/EntityPrinter.php".
- pipedrive (partial): https://support.pipedrive.com/en/article/what-features-do-the-pipedrive-plans-have
  Smart Docs creates documents from templates with CRM data
  (https://support.pipedrive.com/en/article/smart-docs), "Included on Premium
  and higher plans".
- odoo-crm (partial): "mail templates [...] fill a message with the contact's
  fields, and QWeb reports print documents such as quotes on the company
  layout; a new printable letter template is written in QWeb in developer mode
  or built with Studio (Enterprise)".
- kiss (no): "no document or letter generation in src/ or Kiss.Bff/".
- hubspot-crm (unknown): "no article describes generating a printable letter
  filled with record data".

## What changes

- ClientDetail and TicketDetail get a Make a letter action when filinq is
  installed.
- The action opens a dialog listing the filinq templates that belong to
  pipelinq, and makes the letter from the chosen one.
- filinq fills the template with the client, the contact person and, from a
  ticket, the ticket; it stores a copy in the user's Files and hands back the
  PDF.
- pipelinq logs the letter as an outgoing contact moment on the client, with
  channel letter, naming the template and the file.
- Without filinq the action is not offered, and the API says why.

## Out of scope

- Writing templates. Templates are made in filinq's template editor with
  namespace `pipelinq`.
- Tying a template to a pipeline stage or a lifecycle step, so the letter is
  made by itself (Midden-Drenthe's "aan een processtap koppelen"). That is a
  follow-up on this change's endpoint.
- A salutation by gender. The contact schema has no salutation or gender
  field; a template can only use what the record holds.
- Sending the letter. Printing or posting it stays with the user; sending by
  mail or Berichtenbox is the existing messaging path.
- Letters to many clients at once (filinq row `gen-batch`).

## Impact

- New `lib/Service/Letter/FilinqLetterAdapter.php`: resolves filinq's
  `TemplateService` and `DocumentService` through `lib/Support/FleetAppId.php`.
- New `lib/Controller/LetterController.php` with two routes:
  `GET /api/letters/templates` and `POST /api/clients/{id}/letters`.
- New modal `src/modals/LetterFromTemplateModal.vue`, registered in
  `src/registry.js`, opened from `headerActions` on ClientDetail and
  TicketDetail in `src/manifest.json`.
- No new schema. The letter's log is an interaction ticket.
