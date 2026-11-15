// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Eleven small modals mounted for real, for the part their method tests do
 * not reach: what the template wires. Closing the dialog and Cancel tell the
 * parent to close, the screen pop's Select and New contact buttons raise
 * their own events, the BRP purpose dialog keeps the VALUE of the reason and
 * basis an operator picks rather than the option, and the keyword, e-mail and
 * commit forms keep what is typed.
 *
 * @spec openspec/specs/pos/spec.md
 * @spec openspec/specs/cti/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count) => (count === 1 ? one : many)
	globalThis.OC = { requestToken: 'token' }
})

const http = vi.hoisted(() => ({
	get: vi.fn(async () => ({ data: {} })),
	post: vi.fn(async () => ({ data: {} })),
	put: vi.fn(async () => ({ data: {} })),
}))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path, generateOcsUrl: (path) => '/ocs' + path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, translatePlural: (app, one, many, c) => (c === 1 ? one : many) }))
vi.mock('../../src/services/receiptTemplates.js', () => ({ loadActiveReceiptTemplates: vi.fn(async () => []) }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi: new Proxy({}, { get: () => vi.fn(async () => ({})) }) }))
vi.mock('../../src/services/forecastApi.js', () => ({ createOverride: vi.fn(async () => ({})) }))
vi.mock('../../src/services/mailingListApi.js', () => new Proxy({}, {
	get: (target, key) => (key === 'then' ? undefined : vi.fn(async () => [])),
	has: () => true,
}))
vi.mock('../../src/components/SavedReplyPicker.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return { default: defineComponent({ name: 'SavedReplyPicker', render: () => h('div') }) }
})

/**
 * A stand-in component: a div named after the component, rendering every slot,
 * that emits `click` when clicked and a picked value through `pick()`, run
 * through its `reduce` prop the way NcSelect does.
 *
 * @param {string} name Component name.
 * @return {Promise<object>} The component.
 */
async function standIn(name) {
	const { defineComponent, h } = await import('vue')
	return defineComponent({
		name,
		inheritAttrs: false,
		props: { reduce: { type: Function, default: null }, disabled: { type: Boolean, default: false } },
		emits: ['click', 'closing', 'update:modelValue'],
		methods: {
			pick(value) {
				this.$emit('update:modelValue', this.reduce ? this.reduce(value) : value)
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
	NcCheckboxRadioSwitch: await standIn('NcCheckboxRadioSwitch'),
	NcLoadingIcon: await standIn('NcLoadingIcon'),
	NcNoteCard: await standIn('NcNoteCard'),
	NcSelect: await standIn('NcSelect'),
	NcTextArea: await standIn('NcTextArea'),
	NcTextField: await standIn('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', async () => ({
	CnDialog: await standIn('CnDialog'),
	CnNoteCard: await standIn('CnNoteCard'),
	CnTextWidget: await standIn('CnTextWidget'),
}))

const modal = async (name) => (await import(`../../src/modals/${name}.vue`)).default

/**
 * Mount a modal and let its hooks settle.
 *
 * @param {string} name The modal's file name.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function open(name, props = {}) {
	const wrapper = mount(await modal(name), { props, global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return wrapper
}

/**
 * The stand-in button whose text includes the given text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text The text.
 * @return {object} The button wrapper.
 */
function button(wrapper, text) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text().includes(text))
}

const closers = [
	['CommitJustificationModal', {}],
	['ExportTestRunModal', { profileId: 'p-1', autoRun: false }],
	['ForecastOverrideModal', { periodId: 'q1', ownerId: 'ann', level: 'rep' }],
	['EmailReceiptModal', { transactionId: 't-1' }],
	['PrintReceiptModal', { transactionId: 't-1' }],
	['BrpDoelbindingModal', {}],
	['ScreenPopModal', { matches: [] }],
	['SubscriptionManageModal', { contactId: 'c-1' }],
	['NewContactIntakeModal', {}],
]

beforeEach(() => {
	vi.clearAllMocks()
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({}), text: async () => '' })))
})

describe('closing a modal', () => {
	it.each(closers)('%s asks its parent to close when the dialog closes', async (name, props) => {
		const wrapper = await open(name, props)

		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it.each([
		['EmailReceiptModal', { transactionId: 't-1' }],
		['PrintReceiptModal', { transactionId: 't-1' }],
		['KeywordTargetConfirmModal', { proposal: { keyword: 'aanbesteding', topPage: '/diensten' } }],
		['ScreenPopModal', { matches: [] }],
	])('%s closes from Cancel', async (name, props) => {
		const wrapper = await open(name, props)

		button(wrapper, 'Cancel').trigger('click')

		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('the screen pop', () => {
	it('hands the chosen match and the new-contact request to the parent', async () => {
		const match = { id: 'c-7', name: 'Jan Jansen', type: 'person' }
		const wrapper = await open('ScreenPopModal', { matches: [match], e164: '+31612345678' })

		button(wrapper, 'Select').trigger('click')
		wrapper.find('[data-testid="screen-pop-new"]').trigger('click')

		expect(wrapper.emitted('select')).toEqual([[match]])
		expect(wrapper.emitted('intake')).toHaveLength(1)
	})
})

describe('the BRP purpose dialog', () => {
	it('keeps the value of the reason and the legal basis, not the option', async () => {
		const wrapper = await open('BrpDoelbindingModal')
		const [reason, basis] = wrapper.findAllComponents({ name: 'NcSelect' })

		reason.vm.pick({ value: 'identificatie', label: 'Identificatie' })
		basis.vm.pick({ value: 'awb-3-2', label: 'Awb 3:2' })
		await flushPromises()

		expect(wrapper.vm.form.verzoekreden).toBe('identificatie')
		expect(wrapper.vm.form.purposeBinding).toBe('awb-3-2')
	})
})

describe('forms keep what is typed', () => {
	it('the keyword target form keeps its status and page', async () => {
		const wrapper = await open('KeywordTargetConfirmModal', { proposal: { keyword: 'aanbesteding', topPage: '/diensten' } })
		expect(wrapper.vm.targetPageRef).toBe('/diensten')
		const status = wrapper.vm.statusOptions[wrapper.vm.statusOptions.length - 1]

		wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.pick(status)
		const page = wrapper.findAllComponents({ name: 'NcTextField' }).find((f) => f.attributes('label') === 'Page that should win it')
		page.vm.pick('/aanbestedingen')
		await flushPromises()

		expect(wrapper.vm.status).toEqual(status)
		expect(wrapper.vm.targetPageRef).toBe('/aanbestedingen')
	})

	it('the e-mail form keeps the message', async () => {
		const wrapper = await open('WriteEmailModal', { to: 'jan@example.nl' })

		wrapper.findAllComponents({ name: 'NcTextArea' })[0].vm.pick('Beste Jan,')
		await flushPromises()

		expect(wrapper.vm.body).toBe('Beste Jan,')
	})
})
