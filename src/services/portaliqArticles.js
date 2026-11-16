// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Marketing articles live in portaliq (marketing-articles-move-to-portaliq).
 *
 * pipelinq only names them: the template picker lists portaliq's published
 * articles through pipelinq's own endpoint (which checks that portaliq is
 * installed), each picked article links into portaliq, and the old
 * `/articles` addresses send the browser to portaliq's Articles pages.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */

import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'

/**
 * The published portaliq articles a template or a post may pick.
 *
 * @return {Promise<{available: boolean, data: Array<object>}>} `available` is false without portaliq.
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */
export async function fetchPortaliqArticles() {
	const { data } = await axios.get(generateUrl('/apps/pipelinq/api/templates/articles'))
	return {
		available: data?.available === true,
		data: Array.isArray(data?.data) ? data.data : [],
	}
}

/**
 * Where an article opens in portaliq; the Articles page without an id.
 *
 * @param {string} [id] The portaliq article id.
 * @return {string} The address.
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-marketing-menu-no-longer-carries-articles
 */
export function portaliqArticleUrl(id = '') {
	const safe = typeof id === 'string' ? id.trim() : ''
	return generateUrl(safe === '' ? '/apps/portaliq/articles' : `/apps/portaliq/articles/${encodeURIComponent(safe)}`)
}

/**
 * Whether portaliq is enabled for this user, from the dependency statuses the
 * page was rendered with.
 *
 * @return {boolean} True when portaliq answers.
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-marketing-menu-no-longer-carries-articles
 */
export function portaliqEnabled() {
	try {
		const statuses = loadState('pipelinq', 'dependency_statuses', {}) || {}
		return statuses.portaliq?.enabled === true
	} catch {
		return false
	}
}

/**
 * Where an old pipelinq article address goes, or null to stay in pipelinq.
 *
 * @param {object} to The route being entered (`params.id` for one article).
 * @param {boolean} enabled Whether portaliq is enabled.
 * @return {string|null} The portaliq address, or null.
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#scenario-an-old-article-link-opens-the-article-in-portaliq
 */
export function oldArticleTarget(to, enabled) {
	if (!enabled) {
		return null
	}
	const id = to?.params?.id
	return portaliqArticleUrl(typeof id === 'string' && id !== 'new' ? id : '')
}
