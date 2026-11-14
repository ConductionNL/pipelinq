// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blast wizard driven through its controls on screen: each step's
 * fields write into the blast it creates, the stepper and the review's Edit
 * buttons jump back, the consent notice's buttons decide, and Cancel closes.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

/**
 * A stub that renders its slots and keeps the props a test finds it by.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element it renders.
 * @return {object} The stub.
 */
function passthrough(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'label', 'inputLabel', 'modelValue', 'value', 'type', 'variant', 'disabled', 'steps', 'currentIndex'],
		emits: ['click', 'update:modelValue', 'closing', 'jump'],
		render() {
			const slots = Object.entries(this.$slots).map(([key, fn]) => h('div', { 'data-slot': key }, fn?.()))
			return h(tag, { 'data-stub': name, 'data-testid': this.$attrs['data-testid'] }, slots)
		},
	}
}

const infos = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: passthrough('CnDialog'), CnStepper: passthrough('CnStepper') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcCheckboxRadioSwitch: passthrough('NcCheckboxRadioSwitch'),
	NcDateTimePickerNative: passthrough('NcDateTimePickerNative'),
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: passthrough('NcSelect'),
	NcTextField: passthrough('NcTextField'),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showInfo: (m) => infos.push(m) }))
vi.mock('../../src/modals/MissingConsentModal.vue', () => ({ default: { name: 'MissingConsentModal', render: () => null } }))
vi.mock('../../src/services/templatePreviewApi.js', () => ({ previewTemplate: vi.fn() }))

const articles = await import('../../src/services/templatePreviewApi.js')
const { default: BlastWizardDialog } = await import('../../src/dialogs/BlastWizardDialog.vue')

let compliance

/**
 * Mount the wizard open, with a router double, and let it load.
 *
 * @param {object} [props] Extra props.
 * @return {Promise<object>} The wrapper and its router.
 */
async function openWizard(props = {}) {
	const router = { push: vi.fn() }
	const w = mount(BlastWizardDialog, { props: { show: true, ...props }, global: { mocks: { t: globalThis.t, n: globalThis.n, $router: router } } })
	await flushPromises()
	return { w, router }
}

/**
 * The child component with this name whose label, input label or text is the given text.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} text The label or text.
 * @return {object} The component.
 */
function control(w, name, text) {
	const found = w.findAllComponents({ name }).find((c) => c.props('label') === text || c.props('inputLabel') === text || c.text().trim() === text)
	if (!found) {
		throw new Error(`No ${name} "${text}"`)
	}
	return found
}

/**
 * Pick a value in a control and let the wizard react.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} text The label or text.
 * @param {*} value The value.
 * @return {Promise<void>}
 */
async function pick(w, name, text, value) {
	control(w, name, text).vm.$emit('update:modelValue', value)
	await flushPromises()
}

/**
 * Press a button and let the wizard react.
 *
 * @param {object} w The wrapper.
 * @param {string} text The button text.
 * @return {Promise<void>}
 */
async function press(w, text) {
	control(w, 'NcButton', text).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	infos.length = 0
	vi.clearAllMocks()
	compliance = { missingConsent: [] }
	const gets = {
		'/api/segments': { data: [{ id: 'seg1', name: 'Customers', estimatedSize: 42 }] },
		'/api/mailing-lists': { results: [{ id: 'l1', name: 'Newsletter list' }] },
		'/api/templates': [{ id: 'tpl1', name: 'Spring mail', channel: 'email' }],
		'/openconnector/source': { results: [{ uuid: 'src1', title: 'Mailer' }] },
		'/pipelinq/mailTransport': { results: [{ id: 'tr1', displayName: 'SMTP', default: true }, { id: 'tr2', displayName: 'Bulk' }] },
	}
	axios.get = vi.fn(async (url) => {
		if (url.includes('/compliance')) {
			return { data: compliance }
		}
		return { data: gets[Object.keys(gets).find((part) => url.includes(part))] }
	})
	axios.post = vi.fn(async () => ({ data: { valid: true } }))
	articles.previewTemplate.mockResolvedValue({ bodyHtml: '<p>Hi</p>' })
})

describe('the blast wizard, driven on screen', () => {
	it('creates the blast every step on screen describes', async () => {
		const close = vi.fn()
		const { w, router } = await openWizard({ close })

		await pick(w, 'NcTextField', 'Blast name', 'Spring')
		await pick(w, 'NcCheckboxRadioSwitch', 'Email', 'email')
		expect(w.vm.selectedChannel).toBe('email')
		await press(w, 'Next')

		await pick(w, 'NcCheckboxRadioSwitch', 'A mailing list', 'list')
		await pick(w, 'NcSelect', 'Mailing list', { id: 'l1', name: 'Newsletter list' })
		expect(w.text()).toContain('Only confirmed subscribers receive this.')
		await pick(w, 'NcCheckboxRadioSwitch', 'A segment', 'segment')
		await pick(w, 'NcSelect', 'Segment', { id: 'seg1', name: 'Customers', estimatedSize: 42 })
		expect(w.find('.blast-wizard__audience-hint').text()).toBe('Estimated audience: 42')
		await press(w, 'Next')

		await pick(w, 'NcSelect', 'Template', { id: 'tpl1', name: 'Spring mail', channel: 'email' })
		expect(w.vm.selectedTemplate.id).toBe('tpl1')
		await press(w, 'Next')

		await pick(w, 'NcSelect', 'Send through', { id: 'tr2', label: 'Bulk', default: false })
		await pick(w, 'NcSelect', 'Connector source', { id: 'src1', label: 'Mailer' })
		await pick(w, 'NcCheckboxRadioSwitch', 'Schedule for later', 'later')
		await pick(w, 'NcDateTimePickerNative', 'Send at', new Date(2027, 2, 1, 9, 30))
		expect(w.vm.model.scheduledFor).toBe('2027-03-01T09:30')
		await press(w, 'Next')

		await pick(w, 'NcCheckboxRadioSwitch', 'Run an A/B variant test', true)
		expect(w.vm.model.abSplitPercent).toBe(50)
		await w.find('#blast-wizard-ab-split').setValue('70')
		expect(w.find('.blast-wizard__ab-split').text()).toContain('30%')
		await press(w, 'Next')

		expect(w.vm.step).toBe('review')
		axios.post.mockResolvedValueOnce({ data: { id: 'b9' } })
		await press(w, 'Create blast')
		const [url, payload] = axios.post.mock.calls.at(-1)
		expect(url).toBe('/apps/pipelinq/api/blasts')
		expect(payload).toEqual({
			name: 'Spring',
			segmentId: 'seg1',
			listId: '',
			templateId: 'tpl1',
			channel: 'email',
			connectorSourceId: 'src1',
			transportId: 'tr2',
			scheduledFor: expect.any(String),
			abSplitPercent: 70,
		})
		expect(close).toHaveBeenCalledTimes(1)
		expect(router.push).toHaveBeenCalledWith({ name: 'BlastMonitor', params: { id: 'b9' } })
	})

	it('goes back with Back, the stepper and the review Edit buttons, and sends now again', async () => {
		const { w } = await openWizard()
		await pick(w, 'NcTextField', 'Blast name', 'Spring')
		await press(w, 'Next')
		await pick(w, 'NcSelect', 'Segment', { id: 'seg1', name: 'Customers' })
		await press(w, 'Next')
		await pick(w, 'NcSelect', 'Template', { id: 'tpl1', name: 'Spring mail', channel: 'email' })
		await press(w, 'Next')
		await pick(w, 'NcCheckboxRadioSwitch', 'Schedule for later', 'later')
		await pick(w, 'NcCheckboxRadioSwitch', 'Send as soon as the blast is created', 'now')
		expect(w.findComponent({ name: 'NcDateTimePickerNative' }).exists()).toBe(false)
		await press(w, 'Back')
		expect(w.vm.step).toBe('content')
		w.findComponent({ name: 'CnStepper' }).vm.$emit('jump', 'basics')
		await flushPromises()
		expect(w.vm.step).toBe('basics')
		w.vm.goTo(5)
		await flushPromises()
		const edits = w.findAllComponents({ name: 'NcButton' }).filter((c) => c.text().trim() === 'Edit')
		expect(edits.length).toBeGreaterThan(0)
		edits[0].vm.$emit('click')
		await flushPromises()
		expect(w.vm.step).toBe('basics')
	})

	it('asks for consent or skips the contacts without it from the review notice', async () => {
		compliance = { missingConsent: [{ name: 'Ann' }] }
		const { w } = await openWizard()
		w.vm.model.name = 'Spring'
		w.vm.selectedSegment = { id: 'seg1', name: 'Customers' }
		w.vm.selectedTemplate = { id: 'tpl1', name: 'Spring mail', channel: 'email' }
		await flushPromises()
		w.vm.goTo(5)
		await flushPromises()
		expect(w.find('[data-testid="blast-review-consent"]').text()).toContain('Ann')
		await press(w, 'Request consent')
		expect(infos).toEqual(['A consent-request flow will be opened for the listed contacts.'])
		await press(w, 'Skip and send')
		expect(w.vm.consentChoice).toBe('skip')
	})

	it('shows a refused blast under the step, and closes from Cancel and the dialog itself', async () => {
		const close = vi.fn()
		const { w } = await openWizard({ close })
		w.vm.model.name = 'Spring'
		w.vm.selectedSegment = { id: 'seg1', name: 'Customers' }
		w.vm.selectedTemplate = { id: 'tpl1', name: 'Spring mail', channel: 'email' }
		await flushPromises()
		w.vm.goTo(5)
		await flushPromises()
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Quota reached' } } })
		await press(w, 'Create blast')
		expect(w.findAllComponents({ name: 'NcNoteCard' }).map((c) => c.text())).toContain('Quota reached')
		expect(close).not.toHaveBeenCalled()
		await press(w, 'Cancel')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(close).toHaveBeenCalledTimes(2)
	})
})
