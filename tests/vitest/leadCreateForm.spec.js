// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The New lead dialog is CnFormDialog over the lead create form config. These
 * carry the rules the bespoke LeadForm held: no probability input, the client
 * optional and said so, the contact following the client, both created through
 * the app's own dialogs, the stage from the chosen pipeline, a new lead on the
 * default pipeline in the reporting currency, and an empty client saved as no
 * key at all.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { leadCreateFields, leadCreateInitialData, leadCreatePayload } from '../../src/services/leadCreateForm.js'
import { LEAD_PRIORITY_LABELS } from '../../src/utils/enumLabels.js'

const pipelines = [
	{ id: 'p-req', title: 'Requests', entityType: 'request', stages: [{ name: 'New', order: 0 }] },
	{ id: 'p1', title: 'Sales', stages: [{ name: 'Won', order: 9, isClosed: true }, { name: 'Lead', order: 0 }, { name: 'Proposal', order: 1 }] },
	{ id: 'p2', title: 'Renewals', isDefault: true, entityType: 'lead', stages: [{ name: 'Closed', order: 0, isClosed: true }, { name: 'Contacted', order: 1 }] },
]

function fieldsOf () {
  return leadCreateFields({
	translate: (text) => text,
	pipelines,
	sources: ['website', 'referral'],
	sourceLabel: (tag) => tag.toUpperCase(),
	priorityLabels: LEAD_PRIORITY_LABELS,
})
}
const byKey = (key) => fieldsOf().find((f) => f.key === key)

describe('the lead create fields', () => {
	it('has no probability field, and requires only the title and the pipeline', () => {
		const fields = fieldsOf()
		expect(fields.map((f) => f.key)).not.toContain('probability')
		expect(fields.filter((f) => f.required).map((f) => f.key)).toEqual(['title', 'pipeline'])
	})

	it('offers each source by its label and stores the tag', () => {
		expect(byKey('source')).toMatchObject({ enum: ['website', 'referral'], enumLabels: { website: 'WEBSITE', referral: 'REFERRAL' } })
	})

	it('offers the priorities by their readable labels', () => {
		expect(byKey('priority').enumLabels).toEqual({ low: 'Low', normal: 'Normal', high: 'High', urgent: 'Urgent' })
	})

	it('offers only the pipelines that apply to leads', () => {
		expect(byKey('pipeline')).toMatchObject({ enum: ['p1', 'p2'], enumLabels: { p1: 'Sales', p2: 'Renewals' } })
	})

	it('reads the stages of the chosen pipeline', () => {
		expect(byKey('stage').optionsFrom).toMatchObject({ where: { id: '@answer.pipeline' }, itemsPath: 'stages', valueField: 'name' })
	})

	it('keeps the client optional and says so, and creates it through the client dialog', () => {
		const client = byKey('client')
		expect(client.required).toBeFalsy()
		expect(client.description).toBe('Leave empty to save the lead without a client.')
		expect(client).toMatchObject({ allowCreate: true, createModal: 'ClientCreateDialog', reference: { schema: 'client' } })
	})

	it('scopes the contact to the chosen client and creates it for that client', () => {
		expect(byKey('contact')).toMatchObject({
			allowCreate: true,
			pickerFilter: { client: '@answer.client' },
			createModal: 'ContactCreateDialog',
			createModalProps: { client: '@answer.client' },
		})
	})

	it('edits the currency next to the value, as a three-letter code', () => {
		const keys = fieldsOf().map((f) => f.key)
		expect(keys.indexOf('currency')).toBe(keys.indexOf('value') + 1)
		expect(new RegExp(byKey('currency').validation.pattern).test('usd')).toBe(true)
		expect(new RegExp(byKey('currency').validation.pattern).test('DOLLAR')).toBe(false)
	})
})

describe('a new lead', () => {
	it('starts on the default lead pipeline, in its first open stage, in the reporting currency', () => {
		expect(leadCreateInitialData(pipelines, 'USD')).toEqual({ priority: 'normal', currency: 'USD', pipeline: 'p2', stage: 'Contacted' })
	})

	it('starts on the first lead pipeline when none is marked default', () => {
		expect(leadCreateInitialData(pipelines.slice(0, 2), 'EUR')).toMatchObject({ pipeline: 'p1', stage: 'Lead' })
	})

	it('starts without a pipeline when none applies to leads', () => {
		expect(leadCreateInitialData([pipelines[0]], 'EUR')).toEqual({ priority: 'normal', currency: 'EUR' })
	})
})

describe('the saved lead', () => {
	it('sends no client or contact key when none was chosen', () => {
		const lead = leadCreatePayload({ title: 'Website enquiry', pipeline: 'p1', client: null, contact: '', value: null }, pipelines)
		expect(lead).toEqual({ title: 'Website enquiry', pipeline: 'p1' })
	})

	it('keeps a chosen client and writes the currency in upper case', () => {
		expect(leadCreatePayload({ title: 'Renewal', pipeline: 'p1', client: 'c1', currency: ' usd ' }, pipelines)).toMatchObject({ client: 'c1', currency: 'USD' })
	})

	it('drops a stage the chosen pipeline does not have, so the server places the lead', () => {
		expect(leadCreatePayload({ title: 'x', pipeline: 'p1', stage: 'Contacted' }, pipelines)).not.toHaveProperty('stage')
		expect(leadCreatePayload({ title: 'x', pipeline: 'p1', stage: 'Proposal' }, pipelines)).toMatchObject({ stage: 'Proposal' })
	})
})

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(async () => []),
	saveObject: vi.fn(),
	getError: vi.fn(() => ({ message: 'The required property (pipeline) is missing' })),
	collections: { pipeline: [] },
}))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'USD' }) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => storeMock }))
vi.mock('../../src/store/modules/leadSources.js', () => ({
	useLeadSourcesStore: () => ({ fetchSources: vi.fn(async () => []), sourceNames: ['website'] }),
}))
const formMock = vi.hoisted(() => ({ setValidationErrors: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['fields', 'initialData', 'register', 'item', 'dialogTitle', 'confirmLabel', 'cancelLabel', 'feedback'],
		emits: ['confirm', 'close'],
		methods: { setValidationErrors: (...args) => formMock.setValidationErrors(...args) },
		render: () => h('div'),
	},
}))

globalThis.t = (_app, text) => text

const { default: LeadCreateDialog } = await import('../../src/dialogs/LeadCreateDialog.vue')

describe('the New lead dialog', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		storeMock.collections.pipeline = pipelines
	})

	const mountDialog = async () => {
		const push = vi.fn(() => Promise.resolve())
		const wrapper = mount(LeadCreateDialog, { global: { mocks: { $router: { push }, t: (_app, text) => text } } })
		await flushPromises()
		return { wrapper, push }
	}

	it('opens the form once the pipelines are read, with the lead fields and starting values', async () => {
		const { wrapper } = await mountDialog()
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('pipeline', { _limit: 100 })
		const form = wrapper.findComponent({ name: 'CnFormDialog' })
		expect(form.props('register')).toBe('pipelinq')
		expect(form.props('initialData')).toMatchObject({ pipeline: 'p2', currency: 'USD' })
		expect(form.props('fields').map((f) => f.key)).toContain('contact')
	})

	it('saves the lead, reports it and opens it', async () => {
		storeMock.saveObject.mockResolvedValue({ id: 'l-1' })
		const { wrapper, push } = await mountDialog()
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { title: 'Deal', pipeline: 'p1', client: null })
		await flushPromises()
		expect(storeMock.saveObject).toHaveBeenCalledWith('lead', { title: 'Deal', pipeline: 'p1' })
		expect(wrapper.emitted('created')).toEqual([['l-1']])
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(push).toHaveBeenCalledWith({ name: 'LeadDetail', params: { id: 'l-1' } })
	})

	it('keeps the form open with the server message when the save is refused', async () => {
		storeMock.saveObject.mockResolvedValue(null)
		const { wrapper } = await mountDialog()
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { title: 'Deal' })
		await flushPromises()
		expect(formMock.setValidationErrors).toHaveBeenCalledWith({}, 'The required property (pipeline) is missing')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('the lead category', () => {
	it('lets a lead carry a category on the form, which routing suggestions read', async () => {
		const { readFileSync } = await import('node:fs')
		const { resolve } = await import('node:path')
		const schema = JSON.parse(
			readFileSync(resolve(__dirname, '../../lib/Settings/pipelinq_register.json'), 'utf8'),
		).components.schemas.lead
		expect(schema.properties.category.type).toBe('string')
		expect(schema.required || []).not.toContain('category')
		expect(fieldsOf().map((field) => field.key)).toContain('category')
	})
})
