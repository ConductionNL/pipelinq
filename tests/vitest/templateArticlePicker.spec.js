// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/templateArticlePicker.js — the article picker
 * logic on the campaign template form: the missing-marker warning and the
 * saved order the picker must preserve.
 *
 * @spec openspec/changes/marketing-article-hub/specs/marketing-ui/spec.md#requirement-the-templates-form-lets-a-marketer-pick-articles
 */

import { describe, expect, it } from 'vitest'
import {
	articleId,
	hasArticlesMarker,
	orderedArticleIds,
	publishedOnly,
	resolveSelectedArticles,
	shouldWarnMissingMarker,
} from '../../src/services/templateArticlePicker.js'

describe('hasArticlesMarker', () => {
	it('finds the marker in a body that carries it', () => {
		expect(hasArticlesMarker('Hello {{articles}} world')).toBe(true)
	})

	it('reports no marker in a plain body', () => {
		expect(hasArticlesMarker('Hello world')).toBe(false)
	})

	it('survives a non-string body', () => {
		expect(hasArticlesMarker(null)).toBe(false)
		expect(hasArticlesMarker(undefined)).toBe(false)
	})
})

describe('shouldWarnMissingMarker', () => {
	it('warns when articles are picked and the body has no marker', () => {
		expect(shouldWarnMissingMarker(['a1'], 'Plain body')).toBe(true)
	})

	it('does not warn when the body carries the marker', () => {
		expect(shouldWarnMissingMarker(['a1'], 'Body with {{articles}}')).toBe(false)
	})

	it('does not warn when no articles are picked, marker or not', () => {
		expect(shouldWarnMissingMarker([], 'Plain body')).toBe(false)
		expect(shouldWarnMissingMarker([], 'Body with {{articles}}')).toBe(false)
	})
})

describe('articleId', () => {
	it('prefers id over uuid', () => {
		expect(articleId({ id: 'a1', uuid: 'u1' })).toBe('a1')
	})

	it('falls back to uuid when id is absent', () => {
		expect(articleId({ uuid: 'u1' })).toBe('u1')
	})

	it('returns an empty string for a missing article', () => {
		expect(articleId(null)).toBe('')
		expect(articleId({})).toBe('')
	})
})

describe('resolveSelectedArticles', () => {
	const articles = [
		{ id: 'a1', title: 'First' },
		{ id: 'a2', title: 'Second' },
		{ id: 'a3', title: 'Third' },
	]

	it('preserves the SAVED order, not the article list order', () => {
		const result = resolveSelectedArticles(['a3', 'a1'], articles)
		expect(result.map((a) => a.id)).toEqual(['a3', 'a1'])
	})

	it('drops an id with no matching article rather than rendering a gap', () => {
		const result = resolveSelectedArticles(['a1', 'gone', 'a2'], articles)
		expect(result.map((a) => a.id)).toEqual(['a1', 'a2'])
	})

	it('survives empty or non-array inputs', () => {
		expect(resolveSelectedArticles([], articles)).toEqual([])
		expect(resolveSelectedArticles(null, articles)).toEqual([])
		expect(resolveSelectedArticles(['a1'], null)).toEqual([])
	})
})

describe('orderedArticleIds', () => {
	it('extracts ids in the order NcSelect reports them', () => {
		expect(orderedArticleIds([{ id: 'a2' }, { id: 'a1' }])).toEqual(['a2', 'a1'])
	})

	it('survives a non-array', () => {
		expect(orderedArticleIds(null)).toEqual([])
		expect(orderedArticleIds(undefined)).toEqual([])
	})
})

describe('publishedOnly', () => {
	it('offers only published articles', () => {
		const result = publishedOnly([
			{ id: 'a1', status: 'draft' },
			{ id: 'a2', status: 'published' },
			{ id: 'a3', status: 'archived' },
		])
		expect(result.map((a) => a.id)).toEqual(['a2'])
	})

	it('survives a non-array', () => {
		expect(publishedOnly(null)).toEqual([])
	})
})
