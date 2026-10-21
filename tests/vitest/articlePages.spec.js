/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The article pages are manifest config: the lifecycle moves are header
 * actions shown by status, the body and hero image are widgets on the
 * record's fields, and the usage is read from the usages endpoint.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-articles/spec.md#requirement-an-article-moves-through-a-declared-lifecycle
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const doc = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/77-marketing-articles.json'), 'utf8'))
const detail = doc.pages.find((p) => p.id === 'ArticleDetail')
const index = doc.pages.find((p) => p.id === 'Articles')

/**
 * Whether a local visibleWhen holds for an article in a status.
 *
 * @param {object} rule The rule `{ field, op, value }`.
 * @param {string} status The article's status.
 * @return {boolean}
 */
function shows(rule, status) {
	expect(rule.field).toBe('status')
	if (rule.op === 'in') {
		return rule.value.includes(status)
	}
	expect(rule.op).toBe('eq')
	return rule.value === status
}

/**
 * The moves offered for an article in a status.
 *
 * @param {string} status The status.
 * @return {string[]} The action ids.
 */
function movesFor(status) {
	return detail.config.headerActions.filter((a) => shows(a.visibleWhen, status)).map((a) => a.id)
}

describe('article lifecycle moves', () => {
	it('offers the moves the server allows from each status', () => {
		expect(movesFor('draft')).toEqual(['submitForReview', 'publish'])
		expect(movesFor('review')).toEqual(['returnToDraft', 'publish'])
		expect(movesFor('published')).toEqual(['archive'])
		expect(movesFor('archived')).toEqual(['restore'])
	})

	it('offers nothing for a status it has never seen', () => {
		expect(movesFor('retracted')).toEqual([])
	})

	it('publishes and archives through their own endpoints, so the server stamps publishedAt', () => {
		const byId = Object.fromEntries(detail.config.headerActions.map((a) => [a.id, a]))
		expect(byId.publish.url).toBe('/apps/pipelinq/api/articles/@objectId/publish')
		expect(byId.archive.url).toBe('/apps/pipelinq/api/articles/@objectId/archive')
		for (const id of ['submitForReview', 'returnToDraft', 'restore']) {
			expect(byId[id].url).toBe('/apps/pipelinq/api/articles/@objectId/transition')
			expect(byId[id].payload).toEqual({ transition: id })
		}
	})
})

describe('article page widgets', () => {
	const widgets = Object.fromEntries(detail.config.widgets.map((w) => [w.id, w]))

	it('reads the body and hero image from the record', () => {
		expect(widgets['article-body']).toMatchObject({ type: 'markdown', content: { field: 'body' } })
		expect(widgets['article-hero']).toMatchObject({ type: 'image', content: { field: 'heroImage', altField: 'title' } })
		expect(detail.config.bodyWidgets).toBeUndefined()
	})

	it('lists the usage by kind with its status in words', () => {
		const usage = widgets['article-usage']
		expect(usage.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/articles/@objectId/usages', responsePath: 'data' })
		const columns = Object.fromEntries(usage.content.columns.map((c) => [c.key, c]))
		expect(columns.kind.enumLabels).toEqual({ template: 'Campaign template', blast: 'Blast' })
		expect(columns.status.enumLabels.sent).toBe('Sent')
		expect(widgets['article-used-templates'].content.valueField).toBe('counts.template')
		expect(widgets['article-used-blasts'].content.valueField).toBe('counts.blast')
	})

	it('creates an article in the edit modal rather than on a page of its own', () => {
		expect(index.config.headerActions).toEqual([
			{ id: 'create', label: 'New article', icon: 'Plus', type: 'open-modal', target: 'ArticleEditModal' },
		])
		expect(doc.pages.some((p) => p.id === 'ArticleNew')).toBe(false)
	})
})
