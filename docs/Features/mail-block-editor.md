# Mail block editor

Build a marketing email from blocks instead of writing HTML.

## Build a mailing from blocks

Open **Marketing**, **Templates**, **New template** and keep the channel on
email. The message starts in **Build from blocks** with a heading, a text block
and the footer.

- **Add a block** with the buttons above the list: heading, text, image,
  button, divider, spacer, articles. You can also drag a button into the list.
- **Pick a block** in the list to set what it says. A text block takes
  markdown: bold, italic, links and lists are kept, anything else is sent as
  plain text. An image needs an `https://` address and alternative text. A
  button needs its text and an `https://` link; its colour starts as your theme
  colour.
- **Reorder** by dragging the handle, or with **Move up** and **Move down**.
  The buttons work with the keyboard, and focus stays on the block you moved.
- **Preview** shows the message as it will be sent, at desktop and at phone
  width. The server renders it, with the picked articles and your physical
  address in place.

The articles block is where the articles you pick under **Articles** appear.

## What the footer is for

Every marketing email has to say who sent it and how to stop receiving it. The
footer always ends with your physical address and the unsubscribe link. You
can write your own text above them, but you cannot remove them, so a template
built from blocks always passes the compliance check. Fill in **Physical
address** below the message: that is the address the footer shows.

## HTML templates

A template saved before blocks existed opens as HTML, as before. **Write HTML**
turns a blocks template into HTML: the message stays as it is, but the blocks
are not kept, and the form asks before it does this. **Build from blocks** is
offered when the HTML body is empty.

## Mail clients

The message is a single column of 600 pixels built from tables with inline
styles, which is what mail clients render most alike. Rendering in specific
mail clients (Outlook, Gmail, Apple Mail) has not been checked by hand yet;
send a test mailing to yourself before a large send.

## API

- `POST /apps/pipelinq/api/templates` and `PATCH /apps/pipelinq/api/templates/{id}`
  take `editorMode` (`blocks` or `html`) and `blocks`. In Blocks mode the
  server renders `bodyHtml` and `bodyText` from the blocks on every save.
- `POST /apps/pipelinq/api/templates/render` takes `blocks`, `articleIds` and
  `footerOverride` and answers `renderedHtml` and `renderedText` (what a save
  stores) and `bodyHtml` and `bodyText` (what is sent, articles and address in
  place). Marketing managers and administrators only.
