## Why

Pipelinq can send a mailing to a list, but the words inside it exist only in the template that sends them. The same paragraph a marketer writes for a newsletter has to be retyped for a social post and typed a third time into a landing page, and none of the three knows about the others. When a customer story is corrected six weeks later, nothing points at the places it already went.

Phase 2 of the marketing programme fixes that by making the text a first-class object. An article is written once, in markdown, and reused: a mailing embeds it, a social post repurposes it, a portaliq page publishes it. The article knows where it has been used, so a marketer can see the reach of one piece of writing before rewriting it.

It is also the object hermiq needs. Rule 4 of the marketing architecture says agents propose and people dispose, and ADR-088 says an agent-authored artefact carries a durable mark. Without an article object there is nowhere to put a draft an agent wrote and nowhere to record that an agent wrote it. Every later phase (social posts, campaigns, the repurpose actions) hangs off this one.

## What Changes

- One new schema in a register fragment: `article`, with a markdown `body`, a `heroImage`, typed `links[]`, `tags[]`, a `language`, an `author`, a `portalPageRef` and a `draft → review → published → archived` lifecycle declared as an `x-openregister-lifecycle`.
- `agentAuthored` and `agentAuthoredBy` on the article: the ADR-088 mark for a draft an agent wrote, stamped by the writer and never taken from a client request.
- `usedIn` is derived at read time, not stored. `ArticleService::listUsages()` answers which mailings, blasts and templates reference an article; nothing writes a usage list onto the article, so the answer cannot go stale.
- Three seeded Dutch articles per ADR-111: a product update, a customer story and an event announcement, so the Articles page is not empty on a fresh instance.
- An Articles index page and an article detail page, both declarative, with the hero image, the status chip and the rendered markdown body. Editing runs through a modal in its own file using `CnMarkdownEditor` for the body and the Nextcloud Files picker for the hero image.
- `campaignTemplate` gains `articleIds[]`, and `BlastService` renders an `{{articles}}` block into both the HTML and the plain-text body: title, summary, hero image and a "read more" link to the article's `portalPageRef` when one is set, and no link at all when there is none. The Templates form lets a marketer pick the articles, and the blast preview shows them.
- `ArticleService` and a small REST surface: the derived usages, the publish and archive transitions that stamp `publishedAt` and `author`, and the create and update paths that stamp identity. Reading a list of articles stays on OpenRegister's own object API, which the declarative pages already use.

## Capabilities

### New Capabilities

- `marketing-articles`: the article object, its lifecycle, the agent-authored mark, the derived usage answer, the rendered `{{articles}}` block and the pages a marketer writes on.

### Modified Capabilities

- `marketing-blast`: a campaign template may name articles, and a rendered body may carry an `{{articles}}` block whose content comes from them.
- `marketing-ui`: the Templates form gains an article picker, and the Marketing menu gains an Articles entry between Templates and Lists.

## Impact

- **Schemas**: new fragment `lib/Settings/register.d/97-marketing-articles.json`; `campaignTemplate` extended in place by the same fragment.
- **Backend**: `ArticleService`, `ArticleController`, `Marketing\ArticleObjectStore`; `BlastService::renderTemplate()` extended; new routes in `appinfo/routes.php`.
- **Frontend**: `src/manifest.d/77-marketing-articles.json`, two in-body sections, one modal under `src/modals/`, one pure helper under `src/services/`, and an article picker on the existing Templates form.
- **Dependencies**: none new. `CnMarkdownEditor` and the Files picker already ship with `@conduction/nextcloud-vue` and `@nextcloud/dialogs`.
- **Out of scope**: the hermiq marketing agent template and the repurpose actions (their own phase-2 changes, both depending on this one), social posts, and portaliq page creation. `portalPageRef` is a field a marketer fills in by hand until phase 4 writes it.
