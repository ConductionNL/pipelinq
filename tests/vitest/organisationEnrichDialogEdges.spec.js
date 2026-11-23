// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OrganisationEnrichDialog beyond the happy path: ticking a difference to use
 * it, the CSV of the proposals, a lookup or write that fails, an answer that
 * is not a list, and cancelling.
 *
 * @spec openspec/specs/client-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['noClose'], emits: ['closing'], render() { return h('div', { 'data-no-close': String(this.noClose) }, [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnStatusBadge: { props: ['label', 'variant'], render() { return h('span', { class: 'pill', 'data-variant': this.variant }, this.label) } },
}))
vi.mock('@nextcloud/vue', () => {
	const tag = (name) => ({ props: ['disabled', 'variant'], emits: ['click'], render() { return h(name, { disabled: this.disabled ? '' : undefined, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } })
	return { NcButton: tag('button'), NcLoadingIcon: tag('span'), NcNoteCard: tag('div') }
})

let Dialog
beforeAll(async () => {
	Dialog = (await import('../../src/dialogs/OrganisationEnrichDialog.vue')).default
})

const PROPOSALS = {
	proposals: [
		{ clientId: 'c1', name: 'Bakkerij "De Molen"', field: 'kvkNumber', current: '', found: '00001008', source: 'KVK', differs: false },
		{ clientId: 'c2', name: 'Dagblad Drechtsteden B.V.', field: 'place', current: 'Dordrecht', found: 'Zuiddrecht', source: 'KVK', differs: true },
		{ clientId: 'c3', name: 'Scouting', field: 'website', current: null, found: 'https://scouting.example', source: 'KVK', differs: false },
	],
	notFound: [],
}

const fill = (text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
const global = { mixins: [{ methods: { t: (app, text, vars) => fill(text, vars), n: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars).replace('%n', count) } }] }

/**
 * The dialog, loaded.
 *
 * @return {Promise<object>} The wrapper.
 */
async function loaded() {
	const wrapper = mount(Dialog, { props: { selectedIds: ['c1', 'c2', 'c3'] }, global })
	await flushPromises()
	return wrapper
}

/**
 * The footer button whose text starts with the label.
 *
 * @param {object} wrapper The dialog.
 * @param {string} label The label.
 * @return {object} The button.
 */
const footer = (wrapper, label) => wrapper.findAll('button').find((b) => b.text().startsWith(label))

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
	toasts.showError.mockReset()
	toasts.showSuccess.mockReset()
	http.post.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/proposals') ? PROPOSALS : { updated: 2 } }))
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('OrganisationEnrichDialog edges', () => {
	it('labels known fields, keeps unknown ones as is, marks empty values and differences', async () => {
		const wrapper = await loaded()
		const rows = wrapper.findAll('tbody tr')

		expect(rows.map((row) => row.findAll('td')[2].text())).toEqual(['KvK number', 'Place', 'website'])
		expect(rows[0].find('.enrich__empty').text()).toBe('empty')
		expect(rows[1].findAll('td')[3].text()).toBe('Dordrecht')
		expect(rows[1].find('.pill').text()).toBe('differs from what you have')
		expect(rows[0].find('.pill').exists()).toBe(false)
		expect(wrapper.findAll('[data-testid="enrich-summary"] .pill').map((pill) => pill.text())).toEqual(['3 proposals', '1 differs from your details'])
		expect(rows.map((row) => row.find('input').attributes('aria-label'))).toEqual(['Use KvK number for Bakkerij "De Molen"', 'Use Place for Dagblad Drechtsteden B.V.', 'Use website for Scouting'])
	})

	it('ticking a difference adds it to the write; unticking one removes it', async () => {
		const wrapper = await loaded()
		const boxes = wrapper.findAll('tbody input[type="checkbox"]')
		await boxes[1].setValue(true)
		await boxes[2].setValue(false)
		expect(footer(wrapper, 'Fill in').text()).toBe('Fill in 2 fields')

		await footer(wrapper, 'Fill in').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/organisations/enrich/apply', {
			changes: [
				{ clientId: 'c1', field: 'kvkNumber', value: '00001008' },
				{ clientId: 'c2', field: 'place', value: 'Zuiddrecht' },
			],
		})
		expect(toasts.showSuccess).toHaveBeenCalledWith('2 fields filled in.')
	})

	it('with nothing ticked the write is disabled', async () => {
		const wrapper = await loaded()
		for (const box of wrapper.findAll('tbody input[type="checkbox"]')) {
			await box.setValue(false)
		}

		expect(footer(wrapper, 'Fill in').text()).toBe('Fill in 0 fields')
		expect(footer(wrapper, 'Fill in').attributes('disabled')).toBe('')
	})

	it('a failing write shows an error and keeps the dialog open', async () => {
		const wrapper = await loaded()
		http.post.mockRejectedValueOnce(new Error('500'))

		await footer(wrapper, 'Fill in').trigger('click')
		await flushPromises()

		expect(toasts.showError).toHaveBeenCalledWith('Could not fill in the details.')
		expect(bus.emit).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(footer(wrapper, 'Fill in').attributes('disabled')).toBeUndefined()
	})

	it('a failing lookup shows an error and leaves nothing to download or write', async () => {
		http.post.mockRejectedValueOnce(new Error('KvK down'))
		const wrapper = await loaded()

		expect(toasts.showError).toHaveBeenCalledWith('Could not look the organisations up.')
		expect(wrapper.find('[data-testid="enrich-proposals"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="enrich-download"]').attributes('disabled')).toBe('')
	})

	it('an answer whose lists are not lists reads as nothing found', async () => {
		http.post.mockResolvedValueOnce({ data: { proposals: 'none', notFound: null } })
		const wrapper = await loaded()

		expect(wrapper.findAll('[data-testid="enrich-summary"] .pill').map((pill) => pill.text())).toEqual(['0 proposals'])
		expect(wrapper.find('[data-testid="enrich-proposals"]').exists()).toBe(false)
	})

	it('downloads the proposals as a quoted CSV file', async () => {
		const wrapper = await loaded()
		const blobs = []
		const createObjectURL = vi.fn((blob) => { blobs.push(blob); return 'blob:proposals' })
		const revokeObjectURL = vi.fn()
		vi.stubGlobal('URL', { createObjectURL, revokeObjectURL })
		const clicked = []
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() { clicked.push([this.href, this.download]) })

		await wrapper.find('[data-testid="enrich-download"]').trigger('click')
		vi.unstubAllGlobals()

		expect(clicked).toEqual([['blob:proposals', 'organisation-proposals.csv']])
		expect(revokeObjectURL).toHaveBeenCalledWith('blob:proposals')
		expect(blobs[0].type).toBe('text/csv')
		expect(await blobs[0].text()).toBe([
			'"Organisation","Field","Now","Found","Source"',
			'"Bakkerij ""De Molen""","KvK number","","00001008","KVK"',
			'"Dagblad Drechtsteden B.V.","Place","Dordrecht","Zuiddrecht","KVK"',
			'"Scouting","website","","https://scouting.example","KVK"',
			'',
		].join('\n'))
	})

	it('cancel and closing ask to close; while saving the dialog cannot close', async () => {
		const wrapper = await loaded()
		await footer(wrapper, 'Cancel').trigger('click')
		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)

		let release
		http.post.mockImplementationOnce(() => new Promise((resolve) => { release = resolve }))
		await footer(wrapper, 'Fill in').trigger('click')
		expect(wrapper.find('[data-no-close]').attributes('data-no-close')).toBe('true')
		expect(footer(wrapper, 'Cancel').attributes('disabled')).toBe('')
		release({ data: { updated: 'x' } })
		await flushPromises()
		expect(toasts.showSuccess).toHaveBeenCalledWith('0 fields filled in.')
	})
})
