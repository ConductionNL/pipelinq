---
kind: code
---

# Proposal: marketing-mail-templates

## Summary

A marketer writes a mail once and reuses it. This is built: the `campaignTemplate` schema (`lib/Settings/register.d/95-marketing-segmentation-blast.json`) holds the channel, subject, HTML and text body, sender and variable list; a blast points at it through `templateId`; `TemplateRenderer::render()` fills `{{variable}}` placeholders per recipient, HTML-escaped unless written `{{{variable}}}`; the Templates pages are declared in `src/manifest.d/75-marketing-blasts.json`. The marketing specs cover articles in a template (`marketing-blast`) and the unsubscribe footer (`marketing-compliance`), but no requirement says what a template is or how it is reused, so the row cites `marketing-ui`, which does not mention templates.

This change writes the requirement down, and plans the two parts the boards draw that need checking: saving a mail from the newsletter wizard as a template, and a sent mailing keeping the content it was sent with when its template changes later.

## The row this covers

Source: pipelinq `openspec/parity/capabilities.json`.

- **mkt-template** Write a mail once and reuse it as a template (built: `lib/Service/TemplateRenderer.php`, Templates pages). Boards: PqSjablonen, PqNieuwsbriefWizard.

## What changes

1. Spec `marketing-mail-templates`: a template and its parts, reuse in a mailing with personalisation, save as template from the wizard, and the sent content being fixed.
2. Tags on the built code and tests for the renderer rules.
3. Verify the two drawn parts on development and build what is missing.

## Screens

PqSjablonen, PqNieuwsbriefWizard (see `screens.md`).

## Out of scope

- The block editor for the body (row mkt-block-editor, its own change).
- Templates for one-to-one mail from a client record (contact moments).
