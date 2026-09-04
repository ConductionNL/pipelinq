## 1. Schema and seed data

- [x] 1.1 Add `lib/Settings/register.d/97-marketing-articles.json` with the `article` schema, its `x-openregister-lifecycle` on `status`, the `agentAuthored` and `agentAuthoredBy` mark, and `article` listed on the `pipelinq` register; verify `python3 -m json.tool` parses it and `npm run check:manifest` exits 0.
- [x] 1.2 Extend `campaignTemplate` in the same fragment with `articleIds` only, because `components.schemas.*.properties` is an associative node the fragment loader merges recursively while a list such as `required` would be replaced; verify the merged schema still carries `name`, `channel`, `bodyHtml` and `variables`.
- [x] 1.3 Seed three Dutch articles per ADR-111, a product update, a customer story and an event announcement, one of them agent-authored, and name one of them from a seeded campaign template so the usages page is not empty; verify every seeded slug is unique and the referenced template slug exists.
- [x] 1.4 Add every new schema title, property title and enum label to `l10n/en.json` and `l10n/nl.json`, then run `npm run l10n:build`; verify `npm run check:schema-l10n` and `npm run check:l10n-js` exit 0 without moving the baseline.

## 2. Service layer

- [ ] 2.1 Add `lib/Service/ArticleService.php` with list, get, create, update, publish and archive over `Marketing\ListObjectStore`, stamping the author and the timestamps, deriving the slug when none is given, refusing a duplicate slug and refusing a client-supplied agent mark; verify the unit tests cover the duplicate slug, the ignored mark and the second publish keeping the first `publishedAt`.
- [ ] 2.2 Add `listUsages()` deriving the campaign templates and blasts that reference an article, with no write to the article; verify a fixture with one referencing template and one blast returns both, and an unreferenced article returns an empty list.
- [ ] 2.3 Add `expandArticlesMarker()` rendering the `{{articles}}` block for `html` and `text`, with a read-more link only when `portalPageRef` is set, escaping every article value in the HTML form; verify both formats, both link cases and the no-articles case in unit tests.
- [ ] 2.4 Teach `BlastService::renderTemplate()` to expand the marker in both bodies from the template's `articleIds`, leaving a template with no marker and no articles byte-identical; verify the existing blast render tests still pass unchanged.

## 3. HTTP surface

- [ ] 3.1 Add `lib/Controller/ArticleController.php` with index, show, create, update, publish, archive and usages, following the `MailingListController` conventions: uid from `IUserSession`, `ObjectOwnerAccessPolicy` on every method, one generic refusal; verify an unprivileged session is refused on every route.
- [ ] 3.2 Add `preview` to `TemplateController` rendering a template's bodies through `expandArticlesMarker()`; verify it returns the expanded bodies and refuses an unprivileged caller.
- [ ] 3.3 Register the routes in `appinfo/routes.php` with the literal-suffixed paths ahead of the bare parameterised ones; verify the route-auth and route-reachability gates exit 0.

## 4. Interface

- [ ] 4.1 Add `src/manifest.d/77-marketing-articles.json` with the Articles menu entry at order 25, a declarative `type: index` cards view and a `type: detail` page carrying an ADR-062 grid and the two body sections; verify `npm run check:manifest` exits 0 and the menu reads Segments, Templates, Articles, Lists, Blasts, Blast performance.
- [ ] 4.2 Add `src/services/articleStatus.js` mapping a status to its chip vocabulary and building the usage groups, then `ArticleContentSection.vue` and `ArticleUsageSection.vue` under `src/components/marketing/`, registered as `kind: 'section'` with a `_note`; verify the vitest spec covers every status and the unknown-status fallback.
- [ ] 4.3 Add `src/modals/ArticleEditModal.vue` using `CnMarkdownEditor` for the body and the Nextcloud Files picker for the hero image; verify the modal lives in `src/modals/`, the modal-isolation gate exits 0 and every `NcSelect` carries an `inputLabel`.
- [ ] 4.4 Add the article picker to `src/views/templates/TemplateForm.vue`, warning when articles are picked and the body carries no marker, and add the rendered preview to the blast wizard's template step; verify the vitest spec covers the warning and the saved order.

## 5. Tests and documentation

- [ ] 5.1 Add PHPUnit coverage for the lifecycle, the usages, the marker expansion in both formats with and without a portal page ref, and the ignored agent mark; verify `composer test` exits 0.
- [ ] 5.2 Add `tests/e2e/spec-coverage/marketing-articles.spec.ts` covering the index, the rendered body on the detail page and the create-then-publish path, with an `@e2e` annotation per scenario; verify `npm run lint` exits 0.
- [ ] 5.3 Add `docs/user/articles.md` and a paragraph in `docs/Features/marketing.md`; verify both carry the SPDX header and no em-dash.
- [ ] 5.4 Run the full gate set: `composer check:strict`, `npm run format`, `npm run lint`, `npm run test:unit`, `npm run check:manifest`, `npm run check:spec-links`, `npm run check:schema-l10n`, `npm run check:l10n-js` and the hydra gates with `HYDRA_GATE_BASE_REF=origin/development`; verify each exits 0.
