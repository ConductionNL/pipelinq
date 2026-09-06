// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Thin client for the article endpoints the interface calls.
 *
 * Kept apart from the components the same way `mailingListApi.js` is: the
 * endpoint paths live in one place, and the two sections
 * (`ArticleContentSection`, `ArticleUsageSection`) plus the edit modal and
 * the template picker all share it rather than duplicating the fetch shape.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * One page of articles, newest first.
 *
 * @param {number} limit Page size.
 * @return {Promise<Array<object>>} The articles.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export async function fetchArticles(limit = 100) {
	const { data } = await axios.get(
		generateUrl(
			`/apps/pipelinq/api/articles?limit=${encodeURIComponent(limit)}`,
		),
	)
	return data?.data || []
}

/**
 * Where one article has been used.
 *
 * @param {string} articleId The article id.
 * @return {Promise<{data: Array<object>, counts: object}>} The envelope.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-reports-where-it-has-been-used
 */
export async function fetchArticleUsages(articleId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/articles/${articleId}/usages`),
	)
	return data
}

/**
 * Create a new article as a draft.
 *
 * @param {object} payload The article fields.
 * @return {Promise<object>} The created article.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
export async function createArticle(payload) {
	const { data } = await axios.post(
		generateUrl('/apps/pipelinq/api/articles'),
		payload,
	)
	return data?.article || null
}

/**
 * Change an article's editable fields.
 *
 * @param {string} articleId The article id.
 * @param {object} payload The changed fields.
 * @return {Promise<object>} The updated article.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-holds-its-body-as-markdown-and-its-own-identity
 */
export async function updateArticle(articleId, payload) {
	const { data } = await axios.patch(
		generateUrl(`/apps/pipelinq/api/articles/${articleId}`),
		payload,
	)
	return data?.article || null
}

/**
 * Publish an article, stamping the publication moment once.
 *
 * @param {string} articleId The article id.
 * @return {Promise<object>} The published article.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export async function publishArticle(articleId) {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/articles/${articleId}/publish`),
	)
	return data?.article || null
}

/**
 * Archive an article.
 *
 * @param {string} articleId The article id.
 * @return {Promise<object>} The archived article.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export async function archiveArticle(articleId) {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/articles/${articleId}/archive`),
	)
	return data?.article || null
}

/**
 * Apply one of the lifecycle's non-stamping transitions (submit for review,
 * return to draft, restore).
 *
 * @param {string} articleId The article id.
 * @param {string} transition The declared transition name.
 * @return {Promise<object>} The transitioned article.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */
export async function transitionArticle(articleId, transition) {
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/articles/${articleId}/transition`),
		{ transition },
	)
	return data?.article || null
}

/**
 * A campaign template's bodies as they will be sent, with the `{{articles}}`
 * marker already expanded.
 *
 * @param {string} templateId The template id.
 * @return {Promise<{subject: string, bodyHtml: string, bodyText: string, articles: Array<object>}>} The preview.
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export async function previewTemplate(templateId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/templates/${templateId}/preview`),
	)
	return data
}
