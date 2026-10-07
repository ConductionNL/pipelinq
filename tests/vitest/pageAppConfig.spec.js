// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/utils/pageAppConfig.js: the page-level app config that
 * dashboards and detail pages format with, and the dashboard gauge target
 * read from `@config.pipelineTarget`
 * (openspec/changes/pipeline-numbers-tell-the-truth).
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import {
	resolveGaugeTarget,
	seedPageAppConfig,
} from '../../src/utils/pageAppConfig.js'

function gauge() {
	return {
		id: 'pipeline-coverage',
		type: 'gauge',
		content: {
			label: 'Open pipeline vs target',
			labelWithoutTarget: 'Open pipeline (no target set)',
			target: { kind: 'static', value: '@config.pipelineTarget' },
		},
	}
}

describe('resolveGaugeTarget', () => {
	it('fills in the configured target', () => {
		const out = resolveGaugeTarget(gauge(), { pipelineTarget: 750000 })
		expect(out.content.target.value).toBe(750000)
		expect(out.content.label).toBe('Open pipeline vs target')
	})

	it('shows no target when none is configured', () => {
		const out = resolveGaugeTarget(gauge(), {})
		expect(out.content.target.value).toBe(0)
		expect(out.content.label).toBe('Open pipeline (no target set)')
	})

	it('treats zero as no target', () => {
		const out = resolveGaugeTarget(gauge(), { pipelineTarget: 0 })
		expect(out.content.label).toBe('Open pipeline (no target set)')
	})

	it('leaves a numeric target and other widget types alone', () => {
		const fixed = {
			type: 'gauge',
			content: { target: { kind: 'static', value: 10 } },
		}
		expect(resolveGaugeTarget(fixed, {})).toBe(fixed)
		const stat = {
			type: 'stat',
			content: { target: { value: '@config.pipelineTarget' } },
		}
		expect(resolveGaugeTarget(stat, {})).toBe(stat)
	})
})

describe('seedPageAppConfig', () => {
	it('seeds dashboard and detail pages, and skips the rest', () => {
		const m = {
			pages: [
				{ id: 'D', type: 'dashboard', config: { widgets: [gauge()] } },
				{ id: 'X', type: 'detail', config: {} },
				{ id: 'I', type: 'index', config: {} },
			],
		}
		seedPageAppConfig(m, { currency: 'USD', pipelineTarget: 5 })
		expect(m.pages[0].config.appConfig.currency).toBe('USD')
		expect(m.pages[0].config.widgets[0].content.target.value).toBe(5)
		expect(m.pages[1].config.appConfig.currency).toBe('USD')
		expect(m.pages[2].config.appConfig).toBeUndefined()
	})
})

describe('the bundled dashboard', () => {
	const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')
	const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)

	it('reads the gauge target and currency from config, not a literal', () => {
		const coverage = widget('pipeline-coverage')
		expect(coverage.content.target.value).toBe('@config.pipelineTarget')
		expect(coverage.content.format.currency).toBe('@config.currency')
	})

	it('counts only won deals as revenue and only open deals as pipeline', () => {
		for (const id of [
			'revenue-over-time',
			'revenue-by-category',
			'top-customers',
		]) {
			expect(widget(id).content.dataSource.filter).toEqual({ status: 'won' })
		}
		expect(widget('pipeline-by-stage').content.dataSource.filter).toEqual({
			status: 'open',
		})
	})
})
