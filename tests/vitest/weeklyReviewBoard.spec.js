// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqWeekoverzicht: the page header is the shared page header with
 * "Opnieuw samenstellen" and the buildiq button, the agent line says when
 * the agent wrote the summary, and the Dutch strings are in the catalogue.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const review = {
	weekStarting: '2026-09-28',
	summary: 'De Afvalvrije week leverde 212 aanvragen op.',
	highlights: ['Klikken 38% hoger'],
	suggestions: [],
	topicIdeas: [],
	degraded: [],
	agentAuthored: true,
	agentAuthoredBy: 'Navi',
	generatedAt: new Date(2026, 9, 5, 7, 0).toISOString(),
}
vi.mock('../../src/services/journeysApi.js', () => ({
	fetchWeeklyReview: vi.fn(async () => review),
	generateWeeklyReview: vi.fn(async () => review),
}))
vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-GB' }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div />' },
	NcLoadingIcon: { template: '<i />' },
	NcNoteCard: { template: '<div><slot /></div>' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnBuildiqEditButton: { template: '<span class="buildiq" />' },
	CnPageHeader: { props: ['title', 'description'], template: '<header class="cn-page-header"><h1>{{ title }}</h1><p>{{ description }}</p><slot name="extra" /></header>' },
	CnStatusBadge: { props: ['label'], template: '<span>{{ label }}</span>' },
}))

globalThis.t = (_app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
const { default: WeeklyReview } = await import('../../src/views/marketing/WeeklyReview.vue')
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

/**
 * @return {Promise<object>} The mounted page.
 */
async function mountPage() {
	const wrapper = mount(WeeklyReview, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

describe('the weekly review as board PqWeekoverzicht', () => {
	it('uses the shared page header with Compose again and the buildiq button', async () => {
		const wrapper = await mountPage()
		const header = wrapper.find('.cn-page-header')
		expect(header.find('h1').text()).toBe('Weekly review')
		expect(header.find('[data-testid="weekly-review-refresh"]').exists()).toBe(true)
		expect(header.find('.buildiq').exists()).toBe(true)
	})

	it('says when the agent wrote the summary', async () => {
		const text = (await mountPage()).find('[data-testid="weekly-review-agent-mark"]').text()
		expect(text).toMatch(/^An agent wrote this summary: Navi, on Monday 5 October/)
		expect(text).toMatch(/07:00/)
	})

	it('has the agent line and the missing-sources note in Dutch', () => {
		expect(nl['An agent wrote this summary: {agent}, on {when}']).toBe('Een agent schreef deze samenvatting: {agent}, op {when}')
		expect(nl['These sources hold nothing yet, so their absence here is missing data and not a zero: {sources}'])
			.toBe('Deze bronnen bevatten nog niets, dus wat hier ontbreekt is ontbrekende data en geen nul: {sources}')
	})
})
