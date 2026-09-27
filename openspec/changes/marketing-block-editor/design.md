# Design: marketing-block-editor

## Context (read at pipelinq development cfe0a0a51, nextcloud-vue 2.57.1, nextcloud/server master 78f898d0e)

- **The form.** `src/views/templates/TemplateForm.vue` is the custom page
  `TemplateFormView` (`src/registry.js:299`, :939) behind TemplateNew and
  TemplateEdit (`src/manifest.d/75-marketing-blasts.json`). The body is an
  HTML `textarea` (:100-106), the plain text another (:127), the footer
  override a third (:136). Channels are email and SMS (`channelOptions()`,
  :261-266). Articles are picked with an NcSelect (:146-157) and embedded
  where the body holds `{{articles}}`.
- **Reply-to is dropped.** The form shows Reply-to email (:82-90), but the
  save payload (:491-501) leaves `replyTo` out and
  `TemplateController::collectTemplateBody()` (:249-260) does not read it, so
  the field is never saved.
- **Saving and compliance.** `TemplateController::create()` (:101) and
  `update()` hand the whitelisted body to `ComplianceService::createTemplate()`
  (:750), which runs `validateTemplate()` (:612-649): an email template needs
  `{{unsubscribe_link}}` (:96) and either a footer override or one of the
  address tokens (:105). `TemplateController::preview()` (:185) expands
  `{{articles}}` for a saved template only.
- **Sending reads bodyHtml.** `BlastService` decorates `bodyHtml` with
  campaign links (:341) and `MailTransportService` expands the articles
  marker (:313). Neither knows how the body was made.
- **Schema.** `campaignTemplate` in
  `lib/Settings/register.d/95-marketing-segmentation-blast.json` has `name`,
  `channel`, `subject`, `bodyHtml`, `bodyText`, `senderName`, `senderEmail`,
  `replyTo`, `footerOverride`, `variables`, `language`, `createdBy`,
  `createdAt`; `97-marketing-articles.json` adds `articleIds`.
- **Rendering rules already in the app.** `ArticleService::renderArticleHtml()`
  (:571) escapes every value with `htmlspecialchars` and only mails an
  `http(s)` hero image (`mailableHero()`, :643-650).
- **Building blocks in the front end.** pipelinq is Vue 3 with `vuedraggable`
  4 (used in `src/dialogs/PipelineFormDialog.vue:344`) and `dompurify`.
  nextcloud-vue ships `CnMarkdownEditor`, which `src/modals/ArticleEditModal.vue:58`
  uses for article bodies.
- **Brand colour.** `OCP\Defaults::getColorPrimary()` (server
  `lib/public/Defaults.php:191`) returns the theming colour.

## Decisions

### D1. Blocks are data on the template; HTML is what gets sent

`campaignTemplate` gains `blocks` (an ordered array of `{id, type, props}`)
and `editorMode` (`blocks` or `html`). In Blocks mode the server renders
`bodyHtml` and `bodyText` from `blocks` on every save. The send path, the
campaign link decoration and the articles expansion stay exactly as they are.

### D2. One renderer, on the server

`MailBlockRenderer::render(array $blocks, array $options): array{html, text}`
turns blocks into a single-column, 600 pixel wide, table-based layout with
inline styles, which mail clients render predictably. Every text value is
escaped the way `ArticleService` escapes. The text block's markdown arrives as
markdown and is converted on the server with an allow-list of paragraph,
line break, bold, italic, link, and the two list types; links must be
`https:`, `http:` or `mailto:`. The preview calls a new
`POST /api/templates/render` (same `isPrivileged()` gate as `preview()`) so
the marketer sees exactly what the server will send. A second renderer in
the browser would drift.

### D3. The block set

- Heading: text, level 1 or 2.
- Text: markdown through `CnMarkdownEditor`.
- Image: public `http(s)` address, required alt text, optional link. The same
  rule as `mailableHero()`.
- Button: label, `http(s)` address, colour defaulting to the theming primary
  colour.
- Divider and spacer.
- Articles: renders as the `{{articles}}` marker, so the picked articles fill
  it at preview and send time as today.
- Footer: the unsubscribe link and `{{physical_address}}`, editable text
  around both tokens, always last, not removable.

### D4. Drag, and a way without dragging

The palette and the block list use `vuedraggable`. Each block also has Move
up, Move down and Remove buttons with labels, and focus stays on the moved
block. WCAG 2.2 success criterion 2.5.7 asks for a way to do a drag with a
single pointer; the buttons are that way and serve the keyboard too.

### D5. Modes

A new email template opens in Blocks mode with a heading, a text block and the
footer. An existing template has no `blocks` and opens in HTML mode. Switching
from Blocks to HTML keeps the rendered HTML and asks for confirmation, because
the blocks are not kept. Switching from HTML to Blocks is offered only on an
empty body. SMS templates have no Blocks mode.

### D6. Compliance by construction

Because the footer block always carries both tokens, a template made in
Blocks mode passes `validateTemplate()` unless the marketer deletes a token
from the footer text. The footer editor keeps the tokens as fixed chips that
cannot be typed away.

### D7. Save Reply-to

The form adds `replyTo` to its payload and `collectTemplateBody()` reads it.
It is on the file this change rewrites and the schema already has the field.

## Risks

- Mail clients differ. The renderer uses tables and inline styles only, and a
  PHPUnit snapshot per block type guards the output. An Outlook rendering test
  is not automated; the doc names the clients checked by hand.
- The text block's markdown is converted on the server with a narrow
  allow-list. A marketer who needs a table or a colour in text uses HTML mode.
- A block template edited in HTML mode loses its blocks. The confirmation says
  so before it happens.
