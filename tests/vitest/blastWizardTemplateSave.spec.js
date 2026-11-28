// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The newsletter wizard starts from a template on its first step and saves
 * its content as a new template on the content step.
 *
 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-003
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const successes = []
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
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
vi.mock('@nextcloud/dialogs', () => ({ showInfo: () => {}, showSuccess: (m) => successes.push(m) }))
vi.mock('../../src/modals/MissingConsentModal.vue', () => ({ default: stub('MissingConsentModal') }))
vi.mock('../../src/services/templatePreviewApi.js', () => ({ previewTemplate: vi.fn(async () => null), emptyVariables: vi.fn(async () => []) }))

const { templateCopyPayload, saveAsTemplate } = await import('../../src/services/templateFromWizard.js')
const { default: BlastWizardDialog } = await import('../../src/dialogs/BlastWizardDialog.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const n = (app, one, many, count, vars = {}) => t(app, count === 1 ? one : many, vars).replace('%n', String(count))

const monthly = {
	id: 'tpl1',
	name: 'Monthly newsletter',
	channel: 'email',
	subject: 'News for {{preferredName}}',
	bodyHtml: '<p>Hello</p>{{unsubscribe_link}}',
	bodyText: 'Hello {{unsubscribe_link}}',
	senderName: 'Gemeente Zuiddrecht',
	senderEmail: 'nieuws@zuiddrecht.nl',
	replyTo: null,
	editorMode: 'blocks',
	blocks: [{ type: 'text', props: { text: 'Hello' } }],
	articleIds: ['a1'],
	complianceStatus: 'valid',
	createdBy: 'daan',
}
const sms = { id: 'tpl2', name: 'Reminder', channel: 'sms', bodyText: 'See you' }

let posts

beforeEach(() => {
	posts = []
	successes.length = 0
	vi.spyOn(axios, 'get').mockImplementation(async (url) => {
		if (url.endsWith('/api/templates/tpl1')) {
			return { data: monthly }
		}
		if (url.endsWith('/api/templates')) {
			return { data: { results: [{ id: 'tpl1', name: 'Monthly newsletter', channel: 'email' }, sms] } }
		}
		return { data: { results: [] } }
	})
	vi.spyOn(axios, 'post').mockImplementation(async (url, body) => {
		posts.push({ url, body })
		if (url.endsWith('/api/templates')) {
			return { data: { id: 'tpl9', ...body } }
		}
		return { data: { valid: true } }
	})
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('templateCopyPayload', () => {
	it('carries the content fields under the trimmed new name and drops the rest', () => {
		const payload = templateCopyPayload(monthly, '  Event invitation ')
		expect(payload).toEqual({
			name: 'Event invitation',
			channel: 'email',
			subject: 'News for {{preferredName}}',
			bodyHtml: '<p>Hello</p>{{unsubscribe_link}}',
			bodyText: 'Hello {{unsubscribe_link}}',
			senderName: 'Gemeente Zuiddrecht',
			senderEmail: 'nieuws@zuiddrecht.nl',
			editorMode: 'blocks',
			blocks: [{ type: 'text', props: { text: 'Hello' } }],
			articleIds: ['a1'],
		})
		expect(payload).not.toHaveProperty('id')
		expect(payload).not.toHaveProperty('complianceStatus')
		expect(payload).not.toHaveProperty('replyTo')
	})

	it('copes with no template', () => {
		expect(templateCopyPayload(null, 'X')).toEqual({ name: 'X' })
	})
})

describe('saveAsTemplate', () => {
	it('reads the template in full and posts the copy', async () => {
		const created = await saveAsTemplate({ id: 'tpl1', name: 'Monthly newsletter' }, 'Event invitation')
		expect(posts).toHaveLength(1)
		expect(posts[0].url).toContain('/apps/pipelinq/api/templates')
		expect(posts[0].body.name).toBe('Event invitation')
		expect(posts[0].body.subject).toBe('News for {{preferredName}}')
		expect(posts[0].body.blocks).toHaveLength(1)
		expect(created.id).toBe('tpl9')
	})

	it('refuses an empty name without calling the server', async () => {
		await expect(saveAsTemplate({ id: 'tpl1' }, '   ')).rejects.toThrow('Give the template a name')
		expect(posts).toHaveLength(0)
	})
})

describe('BlastWizardDialog templates', () => {
	/**
	 * @return {Promise<object>} The wizard, open and loaded.
	 */
	async function openWizard() {
		const w = shallowMount(BlastWizardDialog, { props: { show: true }, global: { renderStubDefaultSlot: true, mocks: { t, n, $router: { push: vi.fn() } } } })
		await flushPromises()
		return w
	}

	it('offers every template on the first step', async () => {
		const w = await openWizard()
		const picker = w.find('[data-testid="blast-start-template"]')
		expect(picker.exists()).toBe(true)
		expect(w.vm.templates.map((tpl) => tpl.id)).toEqual(['tpl1', 'tpl2'])
	})

	it('starting from a template takes its channel and uses it on the content step', async () => {
		const w = await openWizard()
		w.vm.startFromTemplate(sms)
		await flushPromises()
		expect(w.vm.selectedChannel).toBe('sms')
		expect(w.vm.selectedTemplate).toEqual(sms)
		expect(w.vm.model.templateId).toBe('tpl2')
		expect(w.vm.model.channel).toBe('sms')
	})

	it('clearing the start template leaves the channel alone', async () => {
		const w = await openWizard()
		w.vm.startFromTemplate(sms)
		await flushPromises()
		w.vm.startFromTemplate(null)
		await flushPromises()
		expect(w.vm.selectedTemplate).toBeNull()
		expect(w.vm.selectedChannel).toBe('sms')
	})

	it('saves the content as a new template and uses the copy', async () => {
		const w = await openWizard()
		w.vm.selectedTemplate = { id: 'tpl1', name: 'Monthly newsletter', channel: 'email' }
		w.vm.currentStep = 2
		await flushPromises()
		expect(w.find('[data-testid="blast-save-template"]').exists()).toBe(true)

		w.vm.saveTemplateName = 'Event invitation'
		await w.vm.saveContentAsTemplate()
		await flushPromises()

		const created = posts.find((p) => p.url.endsWith('/api/templates'))
		expect(created.body.name).toBe('Event invitation')
		expect(w.vm.templates.map((tpl) => tpl.name)).toContain('Event invitation')
		expect(w.vm.selectedTemplate.id).toBe('tpl9')
		expect(w.vm.model.templateId).toBe('tpl9')
		expect(w.vm.saveTemplateName).toBe('')
		expect(successes).toEqual(['Saved as template "Event invitation"'])
	})

	it('shows why a save failed and keeps the template in use', async () => {
		const w = await openWizard()
		w.vm.selectedTemplate = { id: 'tpl1', name: 'Monthly newsletter', channel: 'email' }
		await flushPromises()
		axios.post.mockImplementation(async (url) => {
			if (url.endsWith('/api/templates')) {
				const error = new Error('Request failed')
				error.response = { data: { message: 'The template has no unsubscribe link' } }
				throw error
			}
			return { data: { valid: true } }
		})
		w.vm.saveTemplateName = 'Broken'
		await w.vm.saveContentAsTemplate()
		expect(w.vm.saveTemplateError).toBe('The template has no unsubscribe link')
		expect(w.vm.selectedTemplate.id).toBe('tpl1')
		expect(w.vm.savingTemplate).toBe(false)
	})

	it('an empty name is refused before any request', async () => {
		const w = await openWizard()
		w.vm.selectedTemplate = { id: 'tpl1', name: 'Monthly newsletter', channel: 'email' }
		await flushPromises()
		w.vm.saveTemplateName = ''
		await w.vm.saveContentAsTemplate()
		expect(w.vm.saveTemplateError).toBe('Give the template a name')
		expect(posts.filter((p) => p.url.endsWith('/api/templates'))).toHaveLength(0)
	})
})
