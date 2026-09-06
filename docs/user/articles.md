<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Articles

An article is a piece of writing you own: a title, a short summary, a hero
image and a body written in markdown. Write it once and reuse it in a
newsletter. A social post and a portal page will reuse the same article in a
later release.

## At a glance

| You want to ... | Open ... |
| --- | --- |
| Write a new article | **Marketing > Articles > New article** |
| Read or edit an article | **Marketing > Articles > (the article)** |
| See where an article has been used | **Marketing > Articles > (the article)**, the "Where this is used" section |
| Embed articles in a mailing | **Marketing > Templates > (a template)**, the Articles picker |

## 1. Write an article

1. Go to **Marketing > Articles** and click **New article**.
2. Give it a title. A URL-safe slug is derived from it, so you can leave the
   slug field empty.
3. Write a one or two sentence summary. It appears on the card and in an
   embedded newsletter block.
4. Write the body in the markdown editor. Use headings, lists and links the
   same way you would in any markdown file. The stored body is always
   markdown, so it can render into a mailing, a post and a page without any
   one of them owning the markup.
5. Pick a hero image from Nextcloud Files, or paste the address of an image
   hosted elsewhere.
6. Save. The article is stored as a draft.

## 2. The lifecycle

An article moves through four statuses: draft, in review, published and
archived.

| Status | What it means |
| --- | --- |
| Draft | Being written. Not offered to a template or a post. |
| In review | Handed to a colleague to read. |
| Published | Available to a mailing, a post and a page. The publication moment is recorded and never moves, even if you publish again. |
| Archived | Taken out of use. It stays readable, and every mailing or template that already named it keeps naming it. |

Submitting for review, returning to draft and publishing are all actions on
the article page. Archiving an article never removes it from a template or a
blast that already used it: a mailing you already sent still names the
article it embedded.

## 3. Where this is used

Every article page shows a "Where this is used" section. It lists the campaign
templates that embed the article, and the blasts sent from those templates.
The answer is worked out fresh every time you look. Remove an article from a
template's picker, and the usage disappears at once. Nothing needs cleaning
up on the article itself.

## 4. Embed articles in a mailing

Open a campaign template and pick one or more published articles in the
Articles field. They render where the body carries the `{{articles}}` marker,
in the order you picked them. Picking articles for a body with no marker
warns you that they will not appear until you add one.

The rendered block shows each article's title, summary and hero image, with a
read-more link only when the article names a portal page. A newsletter that
inlined every full article would be a newsletter nobody reads, so the block
stays short by design.

## 5. Agent-authored articles

An article an agent drafted or changed carries a mark naming the agent. The
article's own write path sets that mark, never a request. Nobody can claim
credit or blame for a draft they did not write. Edit an agent-authored
article yourself, and you take authorship: the mark clears. Wherever an
article is read, the mark stays visible until then.
