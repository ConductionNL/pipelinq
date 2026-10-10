# Tasks: marketing-articles-move-to-portaliq (pipelinq)

Spec only in this PR. Build after portaliq ships `marketing-articles-from-pipelinq` (schema, pages, usage event). Tier V1.

## 1. Read articles from portaliq

- [ ] 1.1 `ArticleService::loadArticlesByIds()` reads register `portaliq`, schema `article`, guarded by `isInstalled('portaliq')`; empty result and a logged reason when portaliq is absent.
  - spec_ref: `specs/marketing-articles/spec.md#requirement-pipelinq-reads-marketing-articles-from-portaliq`
  - files: `lib/Service/ArticleService.php`, `tests/Unit/Service/ArticleServiceTest.php`
  - test: `vendor/bin/phpunit --no-coverage --filter ArticleServiceTest`
- [ ] 1.2 The template article picker and the social post form list portaliq's published articles.
  - spec_ref: `specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles`
  - files: `src/services/templateArticlePicker.js`, `src/dialogs/TemplateFormDialog.vue`, `src/views/social/SocialPostFormView.vue`

## 2. Contribute usages

- [ ] 2.1 `ArticleUsageListener` answers portaliq's usage event for templates, blasts and social posts.
  - spec_ref: `specs/marketing-articles/spec.md#requirement-pipelinq-tells-portaliq-where-an-article-is-used`
  - files: `lib/Listener/ArticleUsageListener.php`, `lib/AppInfo/Application.php`, its test

## 3. Move the data

- [ ] 3.1 Repair step `MoveMarketingArticlesToPortaliq` as in design section 4: idempotent by `legacyRef`, rewrites references, reports counts.
  - spec_ref: `specs/marketing-articles/spec.md#requirement-existing-pipelinq-articles-move-to-portaliq-once`
  - files: `lib/Repair/MoveMarketingArticlesToPortaliq.php`, `appinfo/info.xml`, its test
  - test: run twice on a seeded instance, second run reports `alreadyMoved` for all three demo articles
- [ ] 3.2 Move the three demo articles from `lib/Settings/pipelinq_example_register.json` to portaliq's example data (portaliq PR).

## 4. Remove the pages and the write path

- [ ] 4.1 Delete `src/manifest.d/77-marketing-articles.json`, the `Articles` Modules card, `ArticleFormView`, `ArticleEditModal`, `ArticleDetailFormDialog`, `ArticleContentSection`, `ArticleUsageSection`, `articlesApi.js`, `articleStatus.js`; add redirects for the old routes.
  - spec_ref: `specs/marketing-ui/spec.md#requirement-the-marketing-menu-no-longer-carries-articles`
  - acceptance: `tests/vitest/modulesPage.spec.js` counts one card fewer; `git grep -n "ArticleEditModal\|articlesApi" src/` is empty
- [ ] 4.2 Remove `ArticleController` and the `/api/articles` routes; drop the `article` schema fragment only after the repair step reported zero failures on the release instances.

## 5. Verify

- [ ] 5.1 `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run test:l10n` once before push.
- [ ] 5.2 Live check: a template naming a moved article previews the article's title and summary.
