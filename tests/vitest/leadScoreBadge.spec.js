// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The score badge, the Leads list cell and the explanation
 * (pipeline-lead-score-call-first). The last specs pin the callers: the
 * list declares the column with the registered widget and a Call first
 * toggle, App.vue registers the widget, the board card mounts the badge and
 * the board table sorts by score, so the badge is actually rendered.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-board-card-shows-the-score-req-lscore-002
 */

import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcPopover: {
		name: 'NcPopover',
		render() {
			return h('div', [this.$slots.trigger?.(), this.$slots.default?.()])
		},
	},
}))

let LeadScoreBadge
let LeadScoreCell
let LeadScoreExplanation

beforeAll(async () => {
	globalThis.t = (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		)
	LeadScoreBadge = (
		await import('../../src/components/leadScore/LeadScoreBadge.vue')
	).default
	LeadScoreCell = (await import('../../src/views/leads/cells/LeadScoreCell.vue'))
		.default
	LeadScoreExplanation = (
		await import('../../src/components/leadScore/LeadScoreExplanation.vue')
	).default
})

const globalMixin = {
	mixins: [{ methods: { t: (...args) => globalThis.t(...args) } }],
}

const read = (p) => readFileSync(resolve(__dirname, '../..', p), 'utf8')

describe('LeadScoreBadge', () => {
	it('shows 92 and tells a screen reader "Score 92, high"', () => {
		const w = mount(LeadScoreBadge, {
			props: { lead: { qualificationScore: 92 }, compact: true },
			global: globalMixin,
		})
		const button = w.find('button')
		expect(button.text()).toBe('92')
		expect(button.attributes('aria-label')).toBe('Score 92, high')
	})

	it.each([
		[85, 'High'],
		[40, 'Medium'],
		[10, 'Low'],
	])('writes the band of %s as the word %s next to the number', (score, word) => {
		const w = mount(LeadScoreBadge, {
			props: { lead: { qualificationScore: score } },
			global: globalMixin,
		})
		expect(w.find('.lead-score-badge__band').text()).toBe(word)
		expect(w.find('.lead-score-badge__value').text()).toBe(String(score))
	})
})

describe('LeadScoreCell', () => {
	it('shows a dash for a lead saved before the score existed', () => {
		const w = mount(LeadScoreCell, {
			props: { value: null, row: { title: 'Old lead' } },
			global: globalMixin,
		})
		expect(w.text()).toBe('—')
		expect(w.find('button').exists()).toBe(false)
	})

	it('shows the column value with its band', () => {
		const w = mount(LeadScoreCell, {
			props: { value: 55, row: { title: 'Lead' } },
			global: globalMixin,
		})
		expect(w.find('button').text()).toContain('55')
		expect(w.find('button').text()).toContain('Medium')
	})
})

describe('LeadScoreExplanation', () => {
	it('lists the criteria that added points and the total', () => {
		const w = mount(LeadScoreExplanation, {
			props: {
				lead: {
					value: 5000,
					client: 'c-1',
					expectedCloseDate: '2026-10-01',
					qualificationScore: 35,
				},
			},
			global: globalMixin,
		})
		const rows = w.findAll('li').map((li) =>
			li
				.findAll('span')
				.map((span) => span.text())
				.join(' '),
		)
		expect(rows).toEqual([
			'Value present +10',
			'Client linked +15',
			'Expected close date set +10',
		])
		expect(w.find('.lead-score-explanation__total').text()).toContain('35')
		expect(w.find('.lead-score-explanation__drift').exists()).toBe(false)
	})

	it('says so when the stored score differs from the listed total', () => {
		const w = mount(LeadScoreExplanation, {
			props: { lead: { value: 5000, qualificationScore: 60 } },
			global: globalMixin,
		})
		expect(w.find('.lead-score-explanation__drift').exists()).toBe(true)
	})
})

describe('callers', () => {
	it('App.vue registers the lead-score cell widget', () => {
		const app = read('src/App.vue')
		expect(app).toMatch(
			/import LeadScoreCell from '\.\/views\/leads\/cells\/LeadScoreCell\.vue'/,
		)
		expect(app).toMatch(/'lead-score': LeadScoreCell/)
	})

	it('the Leads list has a sortable score column and a Call first toggle', () => {
		const list = read('src/views/leads/LeadList.vue')
		expect(list).toMatch(
			/key: 'qualificationScore',[\s\S]{0,120}widget: 'lead-score'/,
		)
		expect(list).toMatch(/Call first/)
		expect(list).toMatch(/CALL_FIRST_SORT/)
		const manifest = JSON.parse(read('src/manifest.json'))
		const leads = manifest.pages.find((p) => p.route === '/leads')
		// The manifest's columns are the ones the page renders. An object
		// column gets no schema defaults, so it sorts only when it says so.
		expect(leads.config.columns).toContainEqual(
			expect.objectContaining({
				key: 'qualificationScore',
				widget: 'lead-score',
				sortable: true,
			}),
		)
		for (const column of leads.config.columns) {
			if (typeof column === 'object') {
				expect(column).toMatchObject({ sortable: true })
			}
		}
	})

	it('the board card mounts the badge for a lead, and the board sorts by score', () => {
		expect(read('src/views/pipeline/PipelineCard.vue')).toMatch(
			/<LeadScoreBadge/,
		)
		const board = read('src/views/pipeline/PipelineBoard.vue')
		expect(board).toMatch(/toggleSort\('score'\)/)
		expect(board).toMatch(/compareCallFirst/)
	})
})
