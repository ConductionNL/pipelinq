---
kind: code
depends_on: []
---

# Proposal: marketing-block-editor

## Summary

A marketer should build a mailing from blocks: a heading, a paragraph, an
image, a button, the chosen articles. Today they write HTML in a text area.
This change adds a block editor to the email template form. The marketer drags
blocks into place or moves them with the keyboard, sees the mail as it will
arrive, and never has to write a tag. The unsubscribe link and the address
block sit at the bottom by default, so a template made this way always passes
the compliance check.

## Motivation

One row of the pipelinq capability matrix (`openspec/parity/capabilities.json`),
decided `build` by the OpenSpec pass of 2026-09-27.

**`mkt-block-editor`**, "Design a mailing by dragging blocks instead of
writing HTML". Rated no, built.state none. Matrix evidence:
"src/views/templates/TemplateForm.vue:100 `<textarea id="template-form-body-html"`:
a mailing template is written as HTML in a textarea; articles can be dropped
in as an {{articles}} block (lib/Service/ArticleService.php), but there is no
drag and drop block designer". Demand: feature request
https://forum.espocrm.com/forum/feature-requests/61838-a-drag-and-drop-html-mail-editor-for-messages-and-templates.
Two competitors rate it yes:

- hubspot-crm: https://knowledge.hubspot.com/marketing-email/create-and-send-marketing-emails,
  "In the marketing email editor ... you can edit its content, appearance, and
  module locations" from templates, without HTML.
- odoo-crm: source read, `addons/mass_mailing` (Community) edits mailings with
  the drag and drop snippet editor of the website builder, with themes and
  building blocks; no HTML is needed.

pipedrive rates it partial (the drag and drop editor is the paid Campaigns
add-on, https://support.pipedrive.com/en/article/drag-drop-editor). espocrm
rates it no (a rich text editor with a code view, the forum request open since
2020), kiss no.

## What changes

- An email template gets a Blocks mode next to the HTML mode. New email
  templates start in Blocks mode.
- Block types: heading, text, image, button, divider, spacer, articles and a
  footer. A palette lists them; the marketer drags one onto the mail or adds
  it with a button.
- Every block can be moved up or down with buttons as well as by dragging, so
  the editor works without a mouse.
- The text block uses the markdown editor pipelinq already uses for articles.
- A preview beside the blocks shows the mail at desktop and phone width, with
  the picked articles filled in.
- On save, pipelinq renders the blocks to mail-safe HTML and plain text on the
  server. The send path keeps reading `bodyHtml` and `bodyText`, so nothing
  about sending changes.
- The footer block carries the unsubscribe link and the address tokens. It
  can be edited but not removed.
- The Reply-to field on the template form is saved. Today the form shows it
  and drops it.

## Out of scope

- Multi-column layouts and saved sections. The first version is one column.
- Picking an image from Nextcloud Files. The image block takes a public web
  address, as an article's hero image does today.
- Converting an existing HTML template into blocks. An HTML template stays in
  HTML mode; a marketer can start a new one in blocks.
- Blocks for SMS templates.

## Impact

- `lib/Settings/register.d/95-marketing-segmentation-blast.json` (or a new
  fragment): `campaignTemplate` gains `blocks` (array) and `editorMode`.
- New `lib/Service/Marketing/MailBlockRenderer.php`; `TemplateController`
  create, update and a new render preview route call it; `ComplianceService`
  validates the rendered result as today.
- `src/views/templates/TemplateForm.vue`: mode switch; new
  `src/components/templates/MailBlockEditor.vue` and one component per block
  editor.
