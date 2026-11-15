// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blast wizard, mounted with axios doubled: what it loads when opened,
 * which step each choice unlocks, template validation and preview, the review
 * rows and consent line, the pre-send consent check, and the blast it creates.
 *
 * @spec openspec/specs/marketing-blast/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const infos = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog'), CnStepper: stub('CnStepper') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcDateTimePickerNative: stub('NcDateTimePickerNative'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showInfo: (m) => infos.push(m) }))
vi.mock('../../src/modals/MissingConsentModal.vue', () => ({ default: stub('MissingConsentModal') }))
vi.mock('../../src/services/articlesApi.js', () => ({ previewTemplate: vi.fn() }))

const articles = await import('../../src/services/articlesApi.js')
const { default: BlastWizardDialog } = await import('../../src/dialogs/BlastWizardDialog.vue')

/**
 * Translate as the identity, filling {placeholders}.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} vars The placeholders.
 * @return {string} The filled text.
 */
const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const n = (app, one, many, count, vars = {}) => t(app, count === 1 ? one : many, vars).replace('%n', String(count))

let gets
let compliance

/**
 * Mount the wizard open, with a router double, and let it load.
 *
 * @param {object} [props] Extra props.
 * @return {Promise<object>} The wrapper and its router.
 */
async function openWizard(props = {}) {
	const router = { push: vi.fn() }
	const w = shallowMount(BlastWizardDialog, { props: { show: true, ...props }, global: { mocks: { t, n, $router: router } } })
	await flushPromises()
	return { w, router }
}

/**
 * Fill the first four steps with valid choices.
 *
 * @param {object} w The wrapper.
 */
async function fillSteps(w) {
	w.vm.model.name = ' Spring '
	w.vm.selectedSegment = { id: 'seg1', name: 'Customers' }
	w.vm.selectedTemplate = { id: 'tpl1', name: 'Newsletter', channel: 'email' }
	await flushPromises()
}

beforeEach(() => {
	infos.length = 0
	vi.clearAllMocks()
	compliance = { missingConsent: [] }
	gets = {
		'/api/segments': { data: [{ id: 'seg1', name: 'Customers' }] },
		'/api/mailing-lists': { results: [{ id: 'l1' }] },
		'/api/templates': [{ id: 'tpl1', channel: 'email' }, { id: 'tpl2', channel: 'sms' }],
		'/openconnector/source': { results: [{ uuid: 'src1', title: 'Mailer' }] },
		'/pipelinq/mailTransport': { results: [{ id: 'tr1', displayName: 'SMTP', default: true }, { id: 'tr2' }] },
	}
	axios.get = vi.fn(async (url) => {
		if (url.includes('/compliance')) {
			if (compliance instanceof Error) {
				throw compliance
			}
			return { data: compliance }
		}
		const key = Object.keys(gets).find((part) => url.includes(part))
		const answer = gets[key]
		if (answer instanceof Error) {
			throw answer
		}
		return { data: answer }
	})
	axios.post = vi.fn(async () => ({ data: { valid: true } }))
	articles.previewTemplate.mockResolvedValue({ bodyHtml: '<p>Hi</p>' })
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('opening', () => {
	it('loads segments, templates, sources and transports, and picks the default transport', async () => {
		const { w } = await openWizard()
		expect(w.vm.segments).toEqual([{ id: 'seg1', name: 'Customers' }])
		expect(w.vm.templates).toHaveLength(2)
		expect(w.vm.connectorSources).toEqual([{ id: 'src1', label: 'Mailer' }])
		expect(w.vm.transports).toEqual([{ id: 'tr1', label: 'SMTP', default: true }, { id: 'tr2', label: 'tr2', default: false }])
		expect([w.vm.selectedTransport.id, w.vm.model.transportId]).toEqual(['tr1', 'tr1'])
		expect(w.vm.filteredTemplates.map((x) => x.id)).toEqual(['tpl1'])
		expect(w.vm.step).toBe('basics')
		expect(w.vm.stepSubtitle).toBe('Step 1 of 6: Basics')
		expect(w.vm.stepperSteps[0]).toEqual({ id: 'basics', label: 'Basics' })
	})

	it('keeps going when sources or transports cannot be loaded', async () => {
		gets['/openconnector/source'] = new Error('x')
		gets['/pipelinq/mailTransport'] = new Error('x')
		gets['/api/segments'] = new Error('x')
		gets['/api/templates'] = new Error('x')
		const { w } = await openWizard()
		expect([w.vm.segments, w.vm.templates, w.vm.connectorSources, w.vm.transports]).toEqual([[], [], [], []])
		expect(w.vm.connectorSourcesError).toContain('Could not load connector sources')
		expect(w.vm.transportsError).toContain('Could not load mail transports')
	})
})

describe('steps', () => {
	it('advances only through valid steps and lets you jump back to any reached one', async () => {
		const { w } = await openWizard()
		w.vm.next()
		expect([w.vm.currentStep, w.vm.reachableStep]).toEqual([0, 0])
		w.vm.model.name = 'Spring'
		w.vm.next()
		expect(w.vm.step).toBe('audience')
		w.vm.goTo(3)
		expect(w.vm.currentStep).toBe(1)
		await fillSteps(w)
		expect([w.vm.canSubmit, w.vm.reachableStep]).toEqual([true, 5])
		w.vm.goTo(5)
		await flushPromises()
		expect([w.vm.isLastStep, w.vm.stepSubtitle]).toEqual([true, 'Step 6 of 6: check everything before the blast is created'])
		w.vm.next()
		expect(w.vm.currentStep).toBe(5)
		w.vm.prev()
		expect(w.vm.currentStep).toBe(4)
		w.vm.currentStep = 0
		w.vm.prev()
		expect(w.vm.currentStep).toBe(0)
		expect(w.vm.stepIndex('content')).toBe(2)
	})

	it('needs a scheduled time only when sending later, and clears it when sending now', async () => {
		const { w } = await openWizard()
		w.vm.scheduleMode = 'later'
		await flushPromises()
		expect(w.vm.stepValid('delivery')).toBe(false)
		w.vm.setScheduledFor(new Date(2026, 4, 3, 9, 5))
		expect([w.vm.model.scheduledFor, w.vm.stepValid('delivery')]).toEqual(['2026-05-03T09:05', true])
		expect(w.vm.reviewWhen()).toBe(new Date('2026-05-03T09:05').toLocaleString())
		w.vm.setScheduledFor(new Date('nope'))
		expect(w.vm.model.scheduledFor).toBe('')
		w.vm.model.scheduledFor = '2026-05-03T09:05'
		w.vm.scheduleMode = 'now'
		await flushPromises()
		expect([w.vm.model.scheduledFor, w.vm.reviewWhen()]).toEqual(['', 'As soon as the blast is created'])
	})

	it('switches between a segment and a mailing list audience', async () => {
		const { w } = await openWizard()
		w.vm.selectedSegment = { id: 'seg1' }
		await flushPromises()
		expect(w.vm.model.segmentId).toBe('seg1')
		w.vm.audienceKind = 'list'
		await flushPromises()
		expect([w.vm.selectedSegment, w.vm.model.segmentId, w.vm.mailingLists, w.vm.hasAudience]).toEqual([null, '', [{ id: 'l1' }], false])
		w.vm.selectedList = { id: 'l1', name: 'Members' }
		await flushPromises()
		expect([w.vm.model.listId, w.vm.hasAudience, w.vm.reviewAudience()]).toEqual(['l1', true, 'Mailing list: Members'])
		await w.vm.loadMailingLists()
		expect(axios.get.mock.calls.filter((c) => c[0].includes('mailing-lists'))).toHaveLength(1)
		w.vm.audienceKind = 'segment'
		await flushPromises()
		expect([w.vm.selectedList, w.vm.model.listId]).toEqual([null, ''])
	})

	it('turns the A/B split back to half when enabled at a full split', async () => {
		const { w } = await openWizard()
		w.vm.model.abSplitPercent = 100
		w.vm.abEnabled = true
		await flushPromises()
		expect([w.vm.model.abSplitPercent, w.vm.reviewAb()]).toEqual([50, 'Variant A 50%, variant B 50%'])
		w.vm.abEnabled = false
		await flushPromises()
		expect(w.vm.reviewAb()).toBe('Off')
	})
})

describe('content', () => {
	it('validates an email template and loads its preview into a sandboxed document', async () => {
		const { w } = await openWizard()
		w.vm.selectedTemplate = { id: 'tpl1', channel: 'email' }
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith(expect.stringMatching(/\/apps\/pipelinq\/api\/templates\/tpl1\/validate$/), { channel: 'email' })
		expect([w.vm.templateValidationError, w.vm.templateChecked, w.vm.stepValid('content')]).toEqual(['', true, true])
		expect(w.vm.previewDocument).toContain("default-src 'none'")
		expect(w.vm.previewDocument).toContain('<body><p>Hi</p></body>')
	})

	it('refuses a template without the unsubscribe token, or one that fails validation', async () => {
		axios.post.mockResolvedValueOnce({ data: { valid: false } })
		const { w } = await openWizard()
		w.vm.selectedTemplate = { id: 'tpl1', channel: 'email' }
		await flushPromises()
		expect(w.vm.templateValidationError).toBe('Template is missing the unsubscribe token or physical address.')
		expect(w.vm.stepValid('content')).toBe(false)
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Broken template' } } })
		await w.vm.validateTemplate()
		expect(w.vm.templateValidationError).toBe('Broken template')
		axios.post.mockRejectedValueOnce({})
		await w.vm.validateTemplate()
		expect(w.vm.templateValidationError).toBe('Template validation failed.')
	})

	it('drops a template of another channel, and skips validation for SMS', async () => {
		articles.previewTemplate.mockRejectedValueOnce(new Error('x'))
		const { w } = await openWizard()
		w.vm.selectedTemplate = { id: 'tpl1', channel: 'email' }
		await flushPromises()
		expect(w.vm.preview).toBeNull()
		w.vm.selectedChannel = 'sms'
		await flushPromises()
		expect([w.vm.selectedTemplate, w.vm.model.channel, w.vm.filteredTemplates.map((x) => x.id)]).toEqual([null, 'sms', ['tpl2']])
		axios.post.mockClear()
		w.vm.selectedTemplate = { id: 'tpl2', channel: 'sms' }
		await flushPromises()
		expect([axios.post.mock.calls.length, w.vm.templateChecked, w.vm.reviewChannel()]).toEqual([0, false, 'SMS'])
	})
})

describe('review and consent', () => {
	it('lists what the blast will do, and counts the contacts without consent', async () => {
		compliance = { missingConsent: [{ name: 'Ann' }, { email: 'b@x' }, { name: 'Carl' }, { name: 'Dee' }, {}] }
		const { w } = await openWizard()
		await fillSteps(w)
		w.vm.selectedConnectorSource = { id: 'src1', label: 'Mailer' }
		w.vm.goTo(5)
		await flushPromises()
		expect(w.vm.reviewMissing).toHaveLength(5)
		expect(w.vm.reviewConsentLine).toBe('5 contacts in this segment have no consent for email. Choose how to continue.')
		expect(w.vm.reviewMissingNames).toBe('Ann, b@x, Carl and 1 other')
		expect(Object.fromEntries(w.vm.reviewRows.map((r) => [r.key, r.value]))).toEqual({
			name: 'Spring', channel: 'Email', audience: 'Segment: Customers', template: 'Newsletter',
			transport: 'SMTP', source: 'Mailer', when: 'As soon as the blast is created', ab: 'Off',
		})
		compliance = new Error('x')
		await w.vm.loadReviewConsent()
		expect(w.vm.reviewMissing).toEqual([])
		w.vm.selectedSegment = null
		await w.vm.loadReviewConsent()
		expect(w.vm.reviewMissingNames).toBe('')
	})

	it('names one missing contact in the singular and falls back to the defaults in review', async () => {
		const { w } = await openWizard()
		w.vm.reviewMissing = [{ name: 'Ann' }]
		w.vm.selectedTransport = null
		expect(w.vm.reviewConsentLine).toBe('1 contact in this segment has no consent for email. Choose how to continue.')
		const rows = Object.fromEntries(w.vm.reviewRows.map((r) => [r.key, r.value]))
		expect([rows.template, rows.transport, rows.source]).toEqual(['None', 'Default transport', 'None'])
	})
})

describe('submit', () => {
	it('creates the blast and opens its monitor', async () => {
		const close = vi.fn()
		const { w, router } = await openWizard({ close })
		await fillSteps(w)
		axios.post.mockResolvedValueOnce({ data: { data: { id: 'b1' } } })
		await w.vm.submit()
		const [url, payload] = axios.post.mock.calls.at(-1)
		expect(url).toMatch(/\/apps\/pipelinq\/api\/blasts$/)
		expect(payload).toEqual({ name: 'Spring', segmentId: 'seg1', listId: '', templateId: 'tpl1', channel: 'email', connectorSourceId: '', transportId: 'tr1', scheduledFor: null, abSplitPercent: 100 })
		expect(close).toHaveBeenCalled()
		expect(router.push).toHaveBeenCalledWith({ name: 'BlastMonitor', params: { id: 'b1' } })
	})

	it('asks how to continue when contacts lack consent, and sends only after skipping them', async () => {
		compliance = { missing: [{ name: 'Ann' }] }
		const { w, router } = await openWizard()
		await fillSteps(w)
		const sending = w.vm.submit()
		await flushPromises()
		expect([w.vm.showConsentModal, w.vm.missingConsentContacts]).toEqual([true, [{ name: 'Ann' }]])
		w.vm.onConsentSkip()
		axios.post.mockResolvedValueOnce({ data: { id: 'b2' } })
		await sending
		expect(w.vm.showConsentModal).toBe(false)
		expect(router.push).toHaveBeenCalledWith({ name: 'BlastMonitor', params: { id: 'b2' } })
	})

	it('creates nothing when the sender cancels or asks for consent first', async () => {
		compliance = { missingConsent: [{ name: 'Ann' }] }
		const { w } = await openWizard()
		await fillSteps(w)
		const cancelled = w.vm.submit()
		await flushPromises()
		w.vm.onConsentCancel()
		await cancelled
		const requested = w.vm.submit()
		await flushPromises()
		w.vm.onConsentRequest()
		await requested
		expect(axios.post.mock.calls.some((c) => c[0].endsWith('/api/blasts'))).toBe(false)
		expect(infos).toEqual(['A consent-request flow will be opened for the listed contacts.'])
		expect(w.vm.submitting).toBe(false)
	})

	it('reports a failed compliance check and a refused blast', async () => {
		compliance = new Error('x')
		const { w } = await openWizard()
		await fillSteps(w)
		await w.vm.submit()
		expect(w.vm.submitError).toBe('Could not run pre-send compliance check.')
		w.vm.consentChoice = 'skip'
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Quota reached' } } })
		await w.vm.submit()
		expect(w.vm.submitError).toBe('Quota reached')
		axios.post.mockRejectedValueOnce({})
		await w.vm.submit()
		expect([w.vm.submitError, w.vm.submitting]).toEqual(['Failed to create blast.', false])
	})
})
