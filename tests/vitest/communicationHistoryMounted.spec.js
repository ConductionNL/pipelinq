// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The communication history panel as it renders: the empty and loading
 * states, the table with its channel and date cells, refresh, paging and
 * opening a contact moment from its row.
 *
 * @spec openspec/specs/entity-notes/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
const lib = vi.hoisted(() => ({ openRowTarget: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (_m, key) => encodeURIComponent(vars[key])),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['disabled'], emits: ['click'], template: '<button class="refresh" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<i class="loading" />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	openRowTarget: lib.openRowTarget,
	CnDetailCard: {
		name: 'CnDetailCard',
		props: ['title'],
		render() {
			return h('section', { 'data-title': this.title }, [this.$slots['header-actions']?.(), this.$slots.default?.()])
		},
	},
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows', 'rowClass'],
		emits: ['rowClick'],
		render() {
			return h('table', this.rows.map((row) => h('tr', { class: this.rowClass(row), onClick: (event) => this.$emit('rowClick', row, event) },
				this.columns.map((column) => h('td', this.$slots['column-' + column.key]
					? this.$slots['column-' + column.key]({ value: row[column.key], row })
					: String(row[column.key] ?? ''))))))
		},
	},
	CnPagination: {
		name: 'CnPagination',
		props: ['currentPage', 'totalPages', 'totalItems', 'currentPageSize'],
		emits: ['pageChanged'],
		render: () => h('nav'),
	},
}))

globalThis.t = (_app, text) => text
const { default: CommunicationHistory } = await import('../../src/components/CommunicationHistory.vue')

const PAGE_ONE = {
	results: [
		{ id: 'cm-1', channel: 'telefoon', subject: 'Vraag over factuur', agent: 'Ilse', timestamp: 'not a date' },
		{ id: 'cm-2', channel: '', subject: 'Zonder kanaal', agent: 'Bas', timestamp: '' },
	],
	total: 12,
	pages: 2,
	page: 1,
}

async function panel() {
	const w = mount(CommunicationHistory, {
		props: { entityType: 'client', entityId: 'client-1' },
		global: { mocks: { t: globalThis.t, $router: { push: vi.fn() } } },
	})
	await flushPromises()
	return w
}

describe('CommunicationHistory on screen', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		lib.openRowTarget.mockReset()
	})

	it('shows the contact moments with their channel and date, and a pager', async () => {
		axiosMock.get.mockResolvedValue({ data: PAGE_ONE })
		const w = await panel()

		expect(w.find('section').attributes('data-title')).toBe('Communication History')
		const rows = w.findAll('tr.communication-history__row')
		expect(rows.map((row) => row.findAll('td').map((cell) => cell.text()))).toEqual([
			['telefoon', 'Vraag over factuur', 'Ilse', 'not a date'],
			['-', 'Zonder kanaal', 'Bas', '-'],
		])
		expect(rows[0].find('.communication-history__channel').attributes('aria-label')).toBe('Phone')
		expect(rows[1].find('.communication-history__channel').attributes('aria-label')).toBe('Channel')
		expect(w.findComponent({ name: 'CnPagination' }).props()).toMatchObject({ currentPage: 1, totalPages: 2, totalItems: 12, currentPageSize: 10 })
	})

	it('opens a contact moment from its row as a ticket', async () => {
		axiosMock.get.mockResolvedValue({ data: PAGE_ONE })
		const w = await panel()

		await w.findAll('tr.communication-history__row')[0].trigger('click')

		expect(lib.openRowTarget).toHaveBeenCalledTimes(1)
		expect(lib.openRowTarget.mock.calls[0][1]).toEqual({ name: 'TicketDetail', params: { id: 'cm-1' } })
	})

	it('pages and refreshes from the first page', async () => {
		axiosMock.get.mockResolvedValue({ data: PAGE_ONE })
		const w = await panel()

		axiosMock.get.mockResolvedValue({ data: { ...PAGE_ONE, page: 2 } })
		w.findComponent({ name: 'CnPagination' }).vm.$emit('pageChanged', 2)
		await flushPromises()
		expect(axiosMock.get).toHaveBeenLastCalledWith('/apps/pipelinq/api/activity/client/client-1', { params: { type: 'contactmomenten', _page: 2, _limit: 10 } })

		await w.find('button.refresh').trigger('click')
		await flushPromises()
		expect(axiosMock.get.mock.calls.at(-1)[1].params._page).toBe(1)
		expect(axiosMock.get).toHaveBeenCalledTimes(3)
	})

	it('says so when there is no history, without a table', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: [], total: 0 } })
		const w = await panel()

		expect(w.text()).toContain('No communication history yet')
		expect(w.find('table').exists()).toBe(false)
	})

	it('shows the loading state while the first page is read', async () => {
		let answer
		axiosMock.get.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		const w = await panel()

		expect(w.find('[role="status"]').text()).toContain('Loading communication history…')
		expect(w.find('button.refresh').attributes('disabled')).toBeDefined()

		answer({ data: PAGE_ONE })
		await flushPromises()
		expect(w.find('[role="status"]').exists()).toBe(false)
	})

	it('accepts only the four entity types it is placed on', () => {
		const { validator } = CommunicationHistory.props.entityType
		expect(['client', 'contact', 'lead', 'request'].every(validator)).toBe(true)
		expect(validator('invoice')).toBe(false)
	})
})
