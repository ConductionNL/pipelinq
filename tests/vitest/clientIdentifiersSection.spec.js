// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Identifiers section on ClientDetail mounted for real: the rows with their
 * check status, Check on a KvK or VAT number only, Add saving the whole list
 * through OpenRegister, a refused number shown as the server worded it with the
 * earlier list kept, and the helpers it uses. HTTP is mocked.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({
	patch: vi.fn(),
	post: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

/**
 * A stand-in component that keeps its string attributes, renders its slots,
 * emits `click` unless disabled and emits a value through `pick()`.
 *
 * @param {string} name Component name.
 * @return {Promise<object>} The component.
 */
async function standIn(name) {
	const { defineComponent, h } = await import('vue')
	return defineComponent({
		name,
		inheritAttrs: false,
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click', 'update:modelValue'],
		methods: {
			pick(value) {
				this.$emit('update:modelValue', value)
			},
		},
		render() {
			const attrs = { class: name, onClick: () => !this.disabled && this.$emit('click') }
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (typeof value === 'string') {
					attrs[key] = value
				}
			}
			return h('div', attrs, Object.values(this.$slots).map((slot) => slot()))
		},
	})
}

vi.mock('@nextcloud/vue', async () => ({
	NcButton: await standIn('NcButton'),
	NcLoadingIcon: await standIn('NcLoadingIcon'),
	NcSelect: await standIn('NcSelect'),
	NcTextField: await standIn('NcTextField'),
}))

const ClientIdentifiersSection = (await import('../../src/components/clients/ClientIdentifiersSection.vue')).default
const { checkableScheme, normaliseNumber, refusalMessage } = await import('../../src/utils/clientIdentifiers.js')

const t = (app, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in params ? String(params[key]) : match))

const IDENTIFIERS = [
	{ scheme: 'kvk', value: '12345678', checkStatus: 'unchecked' },
	{ scheme: 'duns', value: '150483782' },
	{ scheme: 'vat', value: 'DE123456789', checkStatus: 'unchecked', checkSource: 'shillinq not installed', checkedAt: '2026-11-17T09:00:00+00:00' },
]

/**
 * Mount the section.
 *
 * @param {Array<object>} identifiers The client's identifiers.
 * @return {object} The wrapper.
 */
function open(identifiers = IDENTIFIERS) {
	return mount(ClientIdentifiersSection, {
		props: { clientId: 'c1', identifiers },
		global: { mocks: { t } },
	})
}

/**
 * The buttons with the given test id.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} testId The data-testid.
 * @return {Array<object>} The buttons.
 */
function buttons(wrapper, testId) {
	return wrapper.findAllComponents({ name: 'NcButton' }).filter((c) => c.attributes('data-testid') === testId)
}

/**
 * Type a number and pick a scheme in the Add form.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} scheme The scheme id.
 * @param {string} value The number.
 * @return {Promise<void>}
 */
async function typeNumber(wrapper, scheme, value) {
	wrapper.findComponent({ name: 'NcSelect' }).vm.pick({ id: scheme, label: scheme })
	wrapper.findComponent({ name: 'NcTextField' }).vm.pick(value)
	await flushPromises()
}

describe('ClientIdentifiersSection', () => {
	beforeEach(() => {
		http.patch.mockReset()
		http.post.mockReset()
	})

	it('lists each number with its status and offers Check on KvK and VAT only', () => {
		const wrapper = open()

		const rows = wrapper.findAll('[data-testid="client-identifier-row"]')
		expect(rows).toHaveLength(3)
		expect(rows[0].text()).toContain('KvK number')
		expect(rows[0].text()).toContain('12345678')
		expect(rows[0].find('[data-testid="client-identifier-status"]').text()).toBe('Not checked')
		expect(rows[1].text()).toContain('DUNS number')
		expect(rows[2].text()).toContain('VAT numbers are checked when shillinq is installed.')
		expect(buttons(wrapper, 'client-identifier-check').map((b) => b.attributes('aria-label'))).toEqual(['Check 12345678', 'Check DE123456789'])
	})

	it('says so when the client has no numbers', () => {
		expect(open([]).text()).toContain('No official numbers yet.')
	})

	it('adds a number by saving the whole list, normalised, through OpenRegister', async () => {
		http.patch.mockResolvedValue({ data: {} })
		const wrapper = open([IDENTIFIERS[0]])

		await typeNumber(wrapper, 'vat', 'nl 1234.56789 b01')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(http.patch).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/client/c1', {
			identifiers: [IDENTIFIERS[0], { scheme: 'vat', value: 'NL123456789B01', checkStatus: 'unchecked' }],
		})
		expect(wrapper.findAll('[data-testid="client-identifier-row"]')).toHaveLength(2)
		expect(wrapper.emitted('saved')[0][0]).toHaveLength(2)
	})

	it('shows a refused number as the server worded it and keeps the earlier list', async () => {
		http.patch.mockRejectedValue({ response: { status: 400, data: { message: 'The RSIN 123456789 is not valid: it fails the eleven test.' } } })
		const wrapper = open([IDENTIFIERS[0]])

		await typeNumber(wrapper, 'rsin', '123456789')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('The RSIN 123456789 is not valid: it fails the eleven test.')
		expect(wrapper.findAll('[data-testid="client-identifier-row"]')).toHaveLength(1)
	})

	it('checks a KvK number and shows the register\'s answer on that row', async () => {
		http.post.mockResolvedValue({
			data: { identifier: { scheme: 'kvk', value: '12345678', checkStatus: 'valid', checkSource: 'KvK', checkedAt: '2026-11-17T09:00:00+00:00' } },
		})
		const wrapper = open()

		buttons(wrapper, 'client-identifier-check')[0].vm.$emit('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/clients/c1/identifiers/check', { scheme: 'kvk', value: '12345678' })
		const row = wrapper.findAll('[data-testid="client-identifier-row"]')[0]
		expect(row.find('[data-testid="client-identifier-status"]').text()).toBe('Valid')
		expect(row.text()).toContain('Checked with KvK')
		expect(wrapper.findAll('[data-testid="client-identifier-row"]')[1].find('[data-testid="client-identifier-status"]').text()).toBe('Not checked')
	})

	it('shows a refused check', async () => {
		http.post.mockRejectedValue({ response: { status: 403, data: { message: 'You may not open this client.' } } })
		const wrapper = open()

		buttons(wrapper, 'client-identifier-check')[0].vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('You may not open this client.')
	})
})

describe('clientIdentifiers helpers', () => {
	it('normalises a number as the server does', () => {
		expect(normaliseNumber('kvk', ' 1234 5678 ')).toBe('12345678')
		expect(normaliseNumber('vat', 'nl123456789b01')).toBe('NL123456789B01')
		expect(normaliseNumber('oin', '0000-0001.0012')).toBe('000000010012')
	})

	it('checks KvK and VAT numbers only', () => {
		expect(checkableScheme('kvk')).toBe(true)
		expect(checkableScheme('vat')).toBe(true)
		expect(checkableScheme('rsin')).toBe(false)
	})

	it('reads the refusal the server gave, else the fallback', () => {
		expect(refusalMessage({ response: { data: { errors: { message: 'A KvK number has 8 digits.' }, message: 'Validation failed' } } }, 'x')).toBe('A KvK number has 8 digits.')
		expect(refusalMessage({ response: { data: { error: 'Nope' } } }, 'x')).toBe('Nope')
		expect(refusalMessage(new Error('network'), 'The number could not be saved.')).toBe('The number could not be saved.')
		expect(refusalMessage({ response: { data: { message: '', error: '' } } }, 'x')).toBe('x')
		expect(refusalMessage({ response: { data: 'Bad gateway' } }, 'x')).toBe('x')
		expect(refusalMessage({ response: { data: { message: 'Validation failed' } } }, 'x')).toBe('Validation failed')
	})
})
