# Marketing articles move to portaliq

Ruben's decision of 2026-10-09, taken while reviewing the pipelinq screens: marketing articles belong to portaliq. This is pipelinq's share. The counterpart changes are:

- ConductionNL/portaliq `marketing-articles-from-pipelinq`: portaliq owns the `article` schema, the Articles pages and the `marketing-articles` spec, and imports the existing pipelinq articles.
- ConductionNL/design-system: the boards `PqArtikel` and `PqArtikelen` become `portaliq/PtArtikel` and `portaliq/PtArtikelen`, in portaliq's header and side bar.

## Why

pipelinq models two different things that are both called an article.

1. **The KCC knowledge base** (`kennisbank`). Agents on the customer workplace (board `PqWerkplekKlant`) look up answers. This content lives in xWiki and reaches pipelinq through the OpenRegister xWiki leaf or pipelinq's xWiki proxy (`src/components/xwiki/`, `src/store/modules/xwiki.js`). The `kennisbank` spec is already marked deprecated in favour of that leaf. **It stays in pipelinq and this change does not touch it.**
2. **Marketing articles** (`marketing-articles`, `marketing-ui`). Texts written once and reused in a newsletter, a social post and a campaign page. pipelinq owns them today: the `article` schema (`lib/Settings/register.d/97-marketing-articles.json`), `ArticleService` and `ArticleController` (8 routes under `/api/articles`), the pages `Articles`, `ArticleNew` and `ArticleDetail` (`src/manifest.d/77-marketing-articles.json`), the Modules card, and five components (`ArticleEditModal`, `ArticleFormView`, `ArticleContentSection`, `ArticleUsageSection`, `ArticleDetailFormDialog`).

A marketing article is public content. portaliq is the fleet's headless CMS (ADR-086): it already owns pages with a markdown body, media, news items and newsletters, and it already renders the campaign landing pages pipelinq hands it (`LandingPageProvisioningService::articleFor()`). Keeping a second content store in the CRM means two places to write public text, two lifecycles and two hero-image pickers.

## What changes

- **pipelinq loses its marketing article pages.** The `Articles`, `ArticleNew` and `ArticleDetail` pages, the Marketing menu entry and the Modules card go. A marketer who opens the old address is sent to portaliq's Articles page when portaliq is installed.
- **pipelinq loses the article schema and its write path** once the migration has run: `ArticleService` create, update, publish, archive and transition, and the `/api/articles` write routes. The schema stays registered, read only, until the migration reports every object moved.
- **Where pipelinq needs an article, it names the portaliq article.** `campaignTemplate.articleIds`, `socialPost.articleId` and the campaign landing page hold portaliq article ids. Rendering an `{{articles}}` block reads the article from portaliq's register through OpenRegister, guarded by `appManager->isInstalled('portaliq')` (portaliq ships the id `portaliq` today).
- **Usages are answered by the app that holds the reference.** portaliq's article page asks "where is this used?". pipelinq answers for its templates, blasts and social posts by listening to portaliq's string-named usage event, so portaliq never learns pipelinq's schemas.
- **Existing articles move.** A repair step copies every pipelinq `article` object into portaliq's `article` schema, keeps a `legacyRef`, rewrites the references in templates, social posts and campaigns, and is idempotent. Specified here; built after portaliq ships its schema.

## Capabilities

### Modified capabilities

- `marketing-articles`: pipelinq stops owning articles; it reads them from portaliq and contributes usages.
- `marketing-ui`: the article picker on the template form picks portaliq articles; the Articles menu entry leaves pipelinq.

## Impact

- Code that goes: `src/manifest.d/77-marketing-articles.json`, the `Articles` card in `src/manifest.d/98-modules.json`, `src/views/marketing/ArticleFormView.vue`, `src/modals/ArticleEditModal.vue`, `src/dialogs/ArticleDetailFormDialog.vue`, `src/components/marketing/ArticleContentSection.vue`, `src/components/marketing/ArticleUsageSection.vue`, `src/services/articlesApi.js`, `src/services/articleStatus.js`, the article routes in `appinfo/routes.php`, `lib/Controller/ArticleController.php`.
- Code that changes: `lib/Service/ArticleService.php` keeps only `loadArticlesByIds()`, `expandArticlesMarker()` and `renderArticlesBlock()`, reading from portaliq; `lib/Service/Marketing/MailBlockRenderer.php`, `lib/Service/SocialPostService.php`, `lib/Service/LandingPageProvisioningService.php`, `src/services/templateArticlePicker.js`, `src/dialogs/TemplateFormDialog.vue`, `src/views/social/SocialPostFormView.vue`.
- New: `lib/Repair/MoveMarketingArticlesToPortaliq.php`, `lib/Listener/ArticleUsageListener.php`.
- Seed data: the three demo articles in `lib/Settings/pipelinq_example_register.json` move to portaliq's example data.
- **pipelinq now needs portaliq for articles in mailings.** Without portaliq, an `{{articles}}` block renders nothing and the template form says why. Mailings without articles are unaffected.
- The knowledge base (`kennisbank`, xWiki) is not affected.
- Feature tier: V1.
