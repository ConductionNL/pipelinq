// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Pure logic behind the article picker on the campaign template form.
 *
 * Kept apart from `TemplateForm.vue` so it can be unit-tested offline the
 * way `subscriptionState.js` and `articleStatus.js` are — this app's
 * vitest suite carries no component-mounting harness, so behaviour that
 * matters has to live in a plain function to stay testable.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */

/**
 * Whether a template body carries the `{{articles}}` marker.
 *
 * @param {string} body The template's `bodyHtml`.
 * @return {boolean} Whether the marker is present.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export function hasArticlesMarker(body) {
	return typeof body === 'string' && body.includes('{{articles}}')
}

/**
 * Whether the picker should warn that the picked articles will not render.
 *
 * Warns only when articles are actually picked: an empty picker and no
 * marker is simply a template that does not use articles, not a mistake.
 *
 * @param {Array<string>} articleIds The picked article ids.
 * @param {string} body The template's `bodyHtml`.
 * @return {boolean} Whether to show the warning.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export function shouldWarnMissingMarker(articleIds, body) {
	return (
		Array.isArray(articleIds)
		&& articleIds.length > 0
		&& !hasArticlesMarker(body)
	)
}

/**
 * The id an article (or an NcSelect option carrying one) resolves to.
 *
 * @param {object} article An article payload or picker option.
 * @return {string} The id, matching the `uuid`-then-`id` order
 *   `ArticleService`/`ListObjectStore` resolve identity with, or an empty
 *   string.
 */
export function articleId(article) {
	return (article && (article.id || article.uuid)) || ''
}

/**
 * The picked articles, in the SAVED order rather than in whatever order
 * NcSelect's own options list holds them. A picker that read the options
 * list instead would silently reorder the embed on every open.
 *
 * An id with no matching article (removed, or not yet loaded) is dropped
 * rather than rendered as a gap — the same convention
 * `ArticleService::loadArticlesByIds()` uses server-side.
 *
 * @param {Array<string>} articleIds The saved order of article ids.
 * @param {Array<object>} articles The full article list the picker offers.
 * @return {Array<object>} The picked articles, in the saved order.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export function resolveSelectedArticles(articleIds, articles) {
	const safeIds = Array.isArray(articleIds) ? articleIds : []
	const safeArticles = Array.isArray(articles) ? articles : []
	return safeIds
		.map((id) => safeArticles.find((article) => articleId(article) === id))
		.filter(Boolean)
}

/**
 * Extract the ordered id list from a set of NcSelect options just picked.
 *
 * @param {Array<object>} options The options NcSelect reports as selected.
 * @return {Array<string>} The article ids, in NcSelect's own order.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export function orderedArticleIds(options) {
	return (Array.isArray(options) ? options : []).map((option) => articleId(option))
}

/**
 * Only the published articles are offered — an unpublished article may not
 * be embedded (marketing-articles spec, "Only a published article may be
 * embedded in a mailing or a post").
 *
 * @param {Array<object>} articles The full article list.
 * @return {Array<object>} The published subset.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export function publishedOnly(articles) {
	return (Array.isArray(articles) ? articles : []).filter(
		(article) => article && article.status === 'published',
	)
}
