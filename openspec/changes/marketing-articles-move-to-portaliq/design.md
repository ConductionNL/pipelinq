# Design: marketing articles move to portaliq

## Context

Two meanings of "article" live in pipelinq. The KCC knowledge base is served by xWiki through the OpenRegister leaf and stays. The marketing article (schema `article` in the `pipelinq` register) moves to portaliq, which owns public content (ADR-086).

## Decisions

### 1. portaliq owns the schema; pipelinq keeps only references

The `article` schema, its lifecycle (`draft`, `review`, `published`, `archived`), the agent mark (ADR-088) and the slug rule move to portaliq unchanged in shape, so the migration is a copy and not a mapping. pipelinq keeps the ids it already stores (`campaignTemplate.articleIds`, `socialPost.articleId`), now pointing at objects in the `portaliq` register.

### 2. Reading across the app boundary goes through OpenRegister, guarded by the app id

pipelinq reads portaliq articles with `ObjectService` on register `portaliq`, schema `article`. It checks `IAppManager::isInstalled('portaliq')` first. `portaliq` is the id in portaliq's `appinfo/info.xml` today; if portaliq's id ever moves, this lookup moves in the coordinated rename pass, not here. Without portaliq the `{{articles}}` block renders empty and the template form shows a notice; it never errors a send.

### 3. Usages are contributed, not computed by the owner

portaliq cannot know pipelinq's `campaignTemplate`, `blast` and `socialPost` schemas. portaliq dispatches a string-named event (`OCA\Portaliq\Event\ArticleUsagesRequestedEvent`, defined in portaliq's change). pipelinq listens with `class_exists()` guarding the registration, and adds one entry per template, blast and social post that names the article, each with id, display name, kind and a link back into pipelinq. This keeps the rule of today's spec: usage is derived at read time and never stored on the article.

### 4. The migration is a repair step with a legacy reference

`MoveMarketingArticlesToPortaliq` runs on upgrade and through `occ maintenance:repair`:

1. Skip with a log line when portaliq is not installed or its `article` schema is missing.
2. For every pipelinq `article`: find a portaliq article with `legacyRef = pipelinq:<uuid>`; create it when absent, copying every property (title, slug, summary, body, heroImage, links, tags, language, status, author, publishedAt, portalPageRef, agentAuthored, agentAuthoredBy).
3. Rewrite `campaignTemplate.articleIds` and `socialPost.articleId` from the old to the new id.
4. Mark the pipelinq article `migratedTo = <portaliq uuid>` and leave it in place, read only.
5. Report counts (`moved`, `alreadyMoved`, `failed`) and keep the old write routes switched off only when `failed` is zero.

A hero image written `app:pipelinq/marketing/<file>` keeps working, because `resolveImageUrl()` resolves it to pipelinq's `img/` folder wherever pipelinq is installed. A slug that collides in portaliq gets a `-pipelinq` suffix and the report names it.

### 5. The old address redirects

`/apps/pipelinq/articles` and `/apps/pipelinq/articles/:id` stay as routes that redirect to portaliq's Articles page and article, so links in old mail and bookmarks keep working.

## Risks

- **Order.** pipelinq must not drop its pages before portaliq ships its schema and pages. Section 1 of the tasks is gated on portaliq's change.
- **Slug collisions** with existing portaliq content. Handled by the suffix rule and the report.
- **A sent mailing names an article.** The sent HTML is already rendered and stored; it does not re-read the article.

## Not in scope

- The knowledge base (`kennisbank`, xWiki leaf). Unchanged.
- `campaign.articleSummary` and `campaign.articleBody` (the inline landing-page text). A follow-up may let a campaign name a portaliq article instead; this change only makes that possible.
