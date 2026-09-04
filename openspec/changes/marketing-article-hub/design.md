## Context

The marketing register already holds `segment`, `campaignTemplate`, `blast`, `blastDelivery`, `consentRecord`, `attributionLink`, `mailingList` and `subscription`. The list services set the conventions this change follows: schemas in a numbered `lib/Settings/register.d/` fragment, register-scoped object access in one place (`Marketing\ListObjectStore`), a service that validates and stamps identity, a controller that takes the uid from `IUserSession` and checks `ObjectOwnerAccessPolicy` on every method, and a declarative manifest fragment in `src/manifest.d/`.

Two library facts shape the interface. `CnMarkdownEditor` ships in `@conduction/nextcloud-vue` and renders markdown in `edit`, `split`, `preview` and `wysiwyg` modes. The manifest key `config.fieldWidgets[]` validates against the v2 schema but nothing in the library renders it: it appears only inside the compiled validator, never in a component. Declaring a markdown editor there would validate, ship, and silently render nothing.

## Goals / Non-Goals

**Goals:**

- One `article` object, written in markdown, that a mailing, a post and a page can all reuse.
- A lifecycle the schema declares rather than a service implements.
- An answer to "where has this been used" that cannot go stale.
- An `{{articles}}` block that renders the same articles into an HTML body and a plain-text body from one code path.
- The ADR-088 mark on an agent-drafted article, applied by the write path.

**Non-Goals:**

- The hermiq marketing agent, the repurpose actions and the companion context. Each is its own phase-2 change and each depends on this one.
- Social posts and campaigns. `articleId` on a `socialPost` arrives in phase 3.
- Creating the portaliq page. `portalPageRef` is a field a marketer fills in by hand until phase 4 writes it through the contribution contract.
- Collaborative editing of a body. One editor at a time, as everywhere else in pipelinq today.

## Decisions

### The body is markdown, and only markdown

Storing HTML would make the mail renderer the owner of the markup and leave a social adapter with tags it cannot use. Markdown renders down to all three targets and is the format `CnMarkdownEditor` speaks. The `{{articles}}` block does not render the body at all: it renders title, summary, hero and an optional link, because a newsletter that inlines three full articles is a newsletter nobody reads.

### `usedIn` is computed, never stored

The architecture lists `usedIn` under the article, but storing it would need a second write on every template save and would be wrong the moment one is missed. `ArticleService::listUsages()` reads the referencing objects instead: campaign templates whose `articleIds` contain the article, and the blasts built on those templates. Removing a reference removes the usage with no write to the article at all. The cost is a query per read; the article count is small and the answer is only asked for on a detail page.

### The lifecycle is declared, the stamping is not

`status` carries an `x-openregister-lifecycle` with `draft` initial and four transitions. What the grammar cannot express is that publishing stamps `publishedAt` once and never again, so `ArticleService::publish()` owns that and nothing else. Archiving is the same shape. This is the ADR-031 split, and it is why the service is small.

### One store, not a second one

`Marketing\ListObjectStore` takes a schema slug on every call. Nothing in it is list-specific except its name, and it is the one place that carries the `_rbac` and `_multitenancy` flags every marketing call needs. Copying it for one more schema would be the duplication ADR-012 exists to stop, so `ArticleService` uses it as it stands. Renaming it is a separate, wider change: three services and their tests depend on the current name.

### The body renders through an in-body section, not a declarative widget

The built-in `text` widget renders markdown, but from a literal `text` prop in the manifest, and only `config.bodyWidgets[]` props carry `@object.<field>` token resolution on a detail page. `config.fieldWidgets[]` is validated and never rendered. So the rendered body, the hero image, the agent-authored mark and the Edit action live in one registered `kind: 'section'` component with a `_note` saying exactly this. Sections are outside gate-29's ratchet, which counts `kind: "widget"` entries, but the built-in-first rule of ADR-049 still applies and the `_note` is how it is answered. Everything else on the page is declarative: the index is a `type: index`, the detail page is a `type: detail` with an ADR-062 grid, and the data widget renders the article's own fields.

### The rendering lives in one method both callers share

`ArticleService::expandArticlesMarker($body, $articles, $format)` is a pure function over already-loaded articles. `BlastService::renderTemplate()` calls it for the HTML body and again for the text body; `TemplateController::preview()` calls it too, so the preview a marketer reads is produced by the code that will send. A test can call it with fixture arrays and assert both formats, with and without a `portalPageRef`, without an object store at all.

### The REST surface is four methods wide

The declarative index and detail pages read through OpenRegister's own object API, so no controller repeats that. What has no declarative equivalent is the derived usages, the two stamping transitions, and the create and update paths that set the author and refuse a client-supplied agent mark. Those are the routes, and `TemplateController` gains one more for the preview. Anything else would be the pass-through ADR-022 forbids.

## Risks / Trade-offs

- **A section is a custom component.** Two of them, in fact. The alternative was a declarative surface that silently renders nothing, which is worse in a way no gate would catch. If `CnDetailPage` later resolves `@object.<field>` in built-in widget props, the body section collapses into a `text` widget and the change is a manifest edit.
- **Usages cost a query per read.** With thousands of templates this would need an index. At the volume a tenant's marketing holds it is cheaper than the write amplification of the stored alternative, and it is right by construction.
- **The slug is unique by check, not by constraint.** OpenRegister does not enforce uniqueness on a property, so `ArticleService` queries before it writes. Two simultaneous creates could still collide. The consequence is a duplicate slug, not a lost article, and the second one is visible on the index.
- **`{{articles}}` is a marker in a body a marketer types.** A typo renders nothing and says nothing. The Templates form warns when articles are picked and the marker is absent, which covers the case that actually happens.
