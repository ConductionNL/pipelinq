// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Small modals mounted and driven through their rendered buttons and fields:
 * the forecast override (validation, save, refusal), the export test run (auto
 * run, success details, failure list, run again) and the subscription manager
 * (rows, remove one or all, the preference link). The dialog and Nextcloud
 * controls are plain stand-ins that render their slots; the services are doubled.
 *
 * @spec openspec/specs/pos/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const translate = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => (key in vars ? String(vars[key]) : whole))
vi.hoisted(() => {
	globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => (key in vars ? String(vars[key]) : whole))
})

const forecast = vi.hoisted(() => ({ createOverride: vi.fn() }))
vi.mock('../../src/services/forecastApi.js', () => forecast)
const exportApi = vi.hoisted(() => ({ testRun: vi.fn() }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi }))
const mailing = vi.hoisted(() => ({ fetchPreferenceLink: vi.fn(), unsubscribeContact: vi.fn() }))
vi.mock('../../src/services/mailingListApi.js', () => mailing)
const customers = vi.hoisted(() => ({ searchCustomers: vi.fn() }))
const articles = vi.hoisted(() => ({ createArticle: vi.fn(), updateArticle: vi.fn() }))
vi.mock('../../src/services/articlesApi.js', () => articles)
const picker = vi.hoisted(() => ({ pickNodes: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => {
	class FilePickerClosed extends Error {}
	const builder = {
		setMultiSelect: () => builder,
		setMimeTypeFilter: () => builder,
		allowDirectories: () => builder,
		addButton: () => builder,
		build: () => picker,
	}
	return { FilePickerClosed, getFilePickerBuilder: () => builder, showError: vi.fn(), showSuccess: vi.fn() }
})
const http = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({ n: (_app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)) }))
vi.mock('../../src/components/SavedReplyPicker.vue', () => ({
	default: { name: 'SavedReplyPicker', emits: ['pick'], render() { return h('button', { class: 'saved-reply', onClick: () => this.$emit('pick', 'Saved text') }, 'Saved reply') } },
}))
vi.mock('../../src/components/LabelSelect.vue', () => ({
	default: { name: 'LabelSelect', props: ['modelValue'], render() { return h('div', { class: 'labels' }, (this.modelValue || []).join(',')) } },
}))
vi.mock('../../src/services/posCustomerApi.js', () => customers)

vi.mock('@conduction/nextcloud-vue', () => ({
	CnMarkdownEditor: {
		name: 'CnMarkdownEditor',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('textarea', { class: 'markdown', value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
	CnNoteCard: { name: 'CnNoteCard', props: ['text', 'type'], render() { return h('div', { class: 'cn-note' }, this.text) } },
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'subtitle'],
		emits: ['closing'],
		render() {
			return h('section', { class: 'dialog' }, [
				h('h2', this.name),
				h('p', { class: 'dialog__eyebrow' }, this.eyebrow),
				h('p', { class: 'dialog__subtitle' }, this.subtitle),
				this.$slots.default?.(),
				h('footer', this.$slots.actions?.()),
			])
		},
	},
}))
vi.mock('@nextcloud/vue', () => {
	const field = (tag) => ({
		props: ['modelValue', 'label', 'helperText', 'error'],
		emits: ['update:modelValue'],
		render() {
			return h('label', [
				this.label,
				h(tag, { value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) }),
				this.helperText ? h('small', this.helperText) : null,
			])
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			props: ['disabled', 'variant'],
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['modelValue', 'value'],
			emits: ['update:modelValue'],
			render() {
				return h('label', { class: 'radio' }, [
					h('input', { type: 'radio', value: this.value, checked: this.modelValue === this.value, onChange: () => this.$emit('update:modelValue', this.value) }),
					this.$slots.default?.(),
				])
			},
		},
		NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('i', { class: 'spinner' }) },
		NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], render() { return h('div', { class: 'select' }, String(this.modelValue)) } },
		NcNoteCard: { name: 'NcNoteCard', props: ['type'], render() { return h('div', { class: 'note note--' + this.type }, this.$slots.default?.()) } },
		NcTextArea: field('textarea'),
		NcTextField: field('input'),
	}
})

const { default: ForecastOverrideModal } = await import('../../src/modals/ForecastOverrideModal.vue')
const { default: ExportTestRunModal } = await import('../../src/modals/ExportTestRunModal.vue')
const { default: SubscriptionManageModal } = await import('../../src/modals/SubscriptionManageModal.vue')
const { default: CustomerLookupModal } = await import('../../src/modals/CustomerLookupModal.vue')
const { default: NewContactIntakeModal } = await import('../../src/modals/NewContactIntakeModal.vue')
const { default: ArticleEditModal } = await import('../../src/modals/ArticleEditModal.vue')
const { default: SendMessageModal } = await import('../../src/modals/SendMessageModal.vue')
const { default: CtiDispositionModal } = await import('../../src/modals/CtiDispositionModal.vue')

/**
 * Mount a component with the translate mock.
 *
 * @param {object} comp The component.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function open(comp, props) {
	return mount(comp, { props, global: { mocks: { t: translate } } })
}

/**
 * The button with the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object} The button wrapper.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

beforeEach(() => {
	for (const fn of [forecast.createOverride, exportApi.testRun, mailing.fetchPreferenceLink, mailing.unsubscribeContact, customers.searchCustomers, articles.createArticle, articles.updateArticle, picker.pickNodes, http.post]) {
		fn.mockReset()
	}
})

describe('ForecastOverrideModal', () => {
	const props = { periodId: 'p-1', ownerId: 'alice', level: 'rep', currentAmount: 1200 }

	it('names the category and owner, and keeps Save off until a reason is given', async () => {
		const wrapper = open(ForecastOverrideModal, { ...props, category: 'best_case' })
		expect(wrapper.find('.forecast-override__context').text()).toBe('Overriding Best-case for alice')
		expect(wrapper.find('input').element.value).toBe('1200')
		expect(button(wrapper, 'Save').attributes('disabled')).toBeDefined()

		// The disabled button cannot be clicked; a save anyway marks the field.
		await wrapper.find('textarea').setValue('abc')
		await wrapper.vm.save()
		expect(forecast.createOverride).not.toHaveBeenCalled()
		expect(wrapper.find('small').text()).toBe('Please enter at least 5 characters.')
		expect(open(ForecastOverrideModal, props).find('.forecast-override__context').text()).toBe('Overriding Commit for alice')
	})

	it('saves the override and closes', async () => {
		const wrapper = open(ForecastOverrideModal, props)
		forecast.createOverride.mockResolvedValueOnce({ id: 'o-1' })
		await wrapper.find('input').setValue('1500')
		await wrapper.find('textarea').setValue('  Big deal slipped  ')
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()

		expect(forecast.createOverride).toHaveBeenCalledWith({
			period_id: 'p-1',
			override_owner_id: 'alice',
			level: 'rep',
			category: 'commit',
			override_amount: 1500,
			reason: 'Big deal slipped',
		})
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'o-1' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('shows the server refusal, or a fallback, and stays open', async () => {
		const wrapper = open(ForecastOverrideModal, props)
		await wrapper.find('textarea').setValue('Correction')
		forecast.createOverride.mockRejectedValueOnce({ response: { data: { error: 'Period is closed' } } })
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(wrapper.find('.forecast-override__error').text()).toBe('Period is closed')

		forecast.createOverride.mockRejectedValueOnce(new Error('offline'))
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()
		expect(wrapper.find('.forecast-override__error').text()).toBe('Could not save the override.')
		expect(wrapper.emitted('close')).toBeUndefined()

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('ExportTestRunModal', () => {
	it('runs on open and lists the sample details', async () => {
		exportApi.testRun.mockResolvedValueOnce({ success: true, sample_rows: 12, format: 'csv', destination: 'sftp', download_url: '/dl/1', filename: 'sample.csv' })
		const wrapper = open(ExportTestRunModal, { jobId: 'job-1' })
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.export-test-run__loading').exists()).toBe(true)
		await flushPromises()

		expect(exportApi.testRun).toHaveBeenCalledWith('job-1')
		const items = wrapper.findAll('.export-test-run__list li').map((li) => li.text())
		expect(items).toEqual(['Sample rows: 12', 'Format: csv', 'Destination: sftp', 'Download sample file'])
		expect(wrapper.find('a').attributes()).toMatchObject({ href: '/dl/1', download: 'sample.csv' })
		expect(wrapper.emitted('completed')[0][0].sample_rows).toBe(12)
	})

	it('shows a given result without running, with camelCase fields', () => {
		const wrapper = open(ExportTestRunModal, {
			jobId: 'job-1',
			initialResult: { success: true, sampleRows: 3, destinationType: 'nextcloud', downloadUrl: '/dl/2', fileName: 'x.json' },
		})
		expect(exportApi.testRun).not.toHaveBeenCalled()
		expect(wrapper.findAll('.export-test-run__list li').map((li) => li.text())).toEqual(['Sample rows: 3', 'Destination: nextcloud', 'Download sample file'])
		expect(wrapper.find('a').attributes('download')).toBe('x.json')
	})

	it('lists the errors of a failed run and runs again on request', async () => {
		const wrapper = open(ExportTestRunModal, { jobId: 'job-1', autoRun: false })
		expect(wrapper.find('.note--warning').text()).toBe('No test result yet.')

		exportApi.testRun.mockResolvedValueOnce({ success: false, errors: 'Destination unreachable' })
		await button(wrapper, 'Run again').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note--error').text()).toContain('Test run failed.')
		expect(wrapper.findAll('.export-test-run__list li').map((li) => li.text())).toEqual(['Destination unreachable'])

		exportApi.testRun.mockRejectedValueOnce(new Error('timeout'))
		await button(wrapper, 'Run again').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('.export-test-run__list li').map((li) => li.text())).toEqual(['timeout'])

		exportApi.testRun.mockResolvedValueOnce(null)
		await button(wrapper, 'Run again').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('.export-test-run__list li').map((li) => li.text())).toEqual(['No response'])

		exportApi.testRun.mockRejectedValueOnce({})
		await button(wrapper, 'Run again').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('.export-test-run__list li').map((li) => li.text())).toEqual(['Test run failed'])

		await button(wrapper, 'Close').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('SubscriptionManageModal', () => {
	const rows = [{ listId: 'newsletter', state: 'confirmed' }, { listId: 'events', state: 'unsubscribed' }]

	it('lists the subscriptions and lets only an open one be removed', () => {
		const wrapper = open(SubscriptionManageModal, { contactId: 'c-1', subscriptions: rows })
		const items = wrapper.findAll('.subscription-manage__row')
		expect(items).toHaveLength(2)
		expect(items[0].find('span').text()).toBe('newsletter')
		expect(items[0].find('button').attributes('disabled')).toBeUndefined()
		expect(items[1].find('button').attributes('disabled')).toBeDefined()
		expect(open(SubscriptionManageModal, { contactId: 'c-1' }).text()).toContain('This person is on no mailing list.')
	})

	it('removes one list or every list and reports the count', async () => {
		const wrapper = open(SubscriptionManageModal, { contactId: 'c-1', subscriptions: rows })
		mailing.unsubscribeContact.mockResolvedValueOnce({ count: 1 })
		await wrapper.findAll('.subscription-manage__row')[0].find('button').trigger('click')
		await flushPromises()
		expect(mailing.unsubscribeContact).toHaveBeenCalledWith('c-1', 'newsletter', 'crm-request')
		expect(wrapper.find('.note--success').text()).toBe('1 subscription(s) closed. The withdrawal is recorded.')
		expect(wrapper.emitted('changed')).toHaveLength(1)

		mailing.unsubscribeContact.mockResolvedValueOnce({})
		await button(wrapper, 'Remove from every list').trigger('click')
		await flushPromises()
		expect(mailing.unsubscribeContact).toHaveBeenLastCalledWith('c-1', '', 'crm-request')
		expect(wrapper.find('.note--success').text()).toBe('0 subscription(s) closed. The withdrawal is recorded.')

		mailing.unsubscribeContact.mockRejectedValueOnce(new Error('500'))
		await button(wrapper, 'Remove from every list').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note--error').text()).toBe('That did not work. Nothing was changed.')
	})

	it('shows the preference link, or says it could not be made', async () => {
		const wrapper = open(SubscriptionManageModal, { contactId: 'c-1', subscriptions: rows })
		mailing.fetchPreferenceLink.mockResolvedValueOnce('https://cloud.example/prefs/abc')
		await button(wrapper, 'Copy preference link').trigger('click')
		await flushPromises()
		expect(wrapper.find('.subscription-manage__link').text()).toBe('https://cloud.example/prefs/abc')

		mailing.fetchPreferenceLink.mockRejectedValueOnce(new Error('403'))
		await button(wrapper, 'Copy preference link').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note--error').text()).toBe('The link could not be made.')
	})
})

describe('CustomerLookupModal', () => {
	it('searches after two characters and a pause, and lists the results', async () => {
		vi.useFakeTimers()
		try {
			customers.searchCustomers.mockResolvedValueOnce([
				{ id: 'k-1', name: 'Anna de Vries', email: 'anna@example.test', phone: '0612345678' },
				{ id: 'k-2', name: 'Bram Bos', doNotContact: true, doNotContactBadge: 'No contact' },
			])
			const wrapper = open(CustomerLookupModal, {})
			expect(wrapper.text()).toContain('Type at least two characters to search.')

			await wrapper.find('input').setValue('a')
			vi.advanceTimersByTime(400)
			expect(customers.searchCustomers).not.toHaveBeenCalled()

			await wrapper.find('input').setValue('an')
			await wrapper.find('input').setValue('ann')
			vi.advanceTimersByTime(400)
			await flushPromises()
			expect(customers.searchCustomers).toHaveBeenCalledTimes(1)
			expect(customers.searchCustomers).toHaveBeenCalledWith('ann', 20)

			const rows = wrapper.findAll('.customer-lookup__row')
			expect(rows.map((r) => r.find('strong').text())).toEqual(['Anna de Vries', 'Bram Bos'])
			expect(rows[0].find('.customer-lookup__meta').text()).toContain('anna@example.test')
			expect(rows[1].find('.customer-lookup__badge').text()).toContain('No contact')

			await rows[1].trigger('click')
			await rows[0].trigger('keydown', { key: 'Enter' })
			expect(wrapper.emitted('select')).toEqual([[expect.objectContaining({ id: 'k-2' })], [expect.objectContaining({ id: 'k-1' })]])

			await wrapper.find('input').setValue('x')
			expect(wrapper.text()).toContain('Type at least two characters to search.')
			wrapper.unmount()
		} finally {
			vi.useRealTimers()
		}
	})

	it('says when nothing matches, offers a retry after an error, and cancels', async () => {
		const wrapper = open(CustomerLookupModal, {})
		await wrapper.find('input').setValue('zz')
		customers.searchCustomers.mockResolvedValueOnce([])
		await wrapper.vm.runSearch()
		await flushPromises()
		expect(wrapper.text()).toContain('No results. Try a different search.')

		customers.searchCustomers.mockRejectedValueOnce(new Error('500'))
		await wrapper.vm.runSearch()
		await flushPromises()
		expect(wrapper.find('.customer-lookup__error').text()).toContain('Error searching. Try again later.')

		customers.searchCustomers.mockResolvedValueOnce([{ id: 'k-1', name: 'Anna' }])
		await button(wrapper, 'Retry').trigger('click')
		await flushPromises()
		expect(wrapper.findAll('.customer-lookup__row')).toHaveLength(1)

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})

describe('NewContactIntakeModal', () => {
	it('prefills the caller number and names the queue', () => {
		const wrapper = open(NewContactIntakeModal, { e164: '+31612345678', queueName: 'Sales' })
		expect(wrapper.find('.dialog__eyebrow').text()).toBe('Incoming call · queue Sales')
		expect(wrapper.find('.dialog__subtitle').text()).toContain('queue Sales')
		expect(wrapper.find('.cn-note').text()).toContain('No contact found for')
		expect(wrapper.find('[data-testid="cti-intake-submit"]').attributes('disabled')).toBeDefined()
		expect(open(NewContactIntakeModal, {}).find('.dialog__eyebrow').text()).toBe('Incoming call')
	})

	it('submits the form once a name is given, falling back to the caller number', async () => {
		const wrapper = open(NewContactIntakeModal, { e164: '+31612345678' })
		const inputs = wrapper.findAll('input')
		await inputs[0].setValue('Anna de Vries')
		await inputs[1].setValue('  ')
		await inputs[2].setValue('anna@example.test')
		await inputs[3].setValue('Acme')
		await wrapper.find('textarea').setValue('Wants a quote')
		await wrapper.find('[data-testid="cti-intake-submit"]').trigger('click')

		expect(wrapper.emitted('submit')).toEqual([[{
			name: 'Anna de Vries',
			phone: '+31612345678',
			email: 'anna@example.test',
			organisation: 'Acme',
			notes: 'Wants a quote',
		}]])

		await inputs[0].setValue('A')
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')).toHaveLength(1)

		await wrapper.find('[data-testid="cti-intake-cancel"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('ArticleEditModal', () => {
	it('creates an article once it has a title, with the tags and links', async () => {
		const wrapper = open(ArticleEditModal, {})
		expect(wrapper.find('h2').text()).toBe('New article')
		expect(wrapper.find('[data-testid="article-edit-save"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('.select').text()).toBe('nl')

		await wrapper.find('#article-edit-title input').setValue('Waste collection')
		await wrapper.find('.markdown').setValue('## Body')
		articles.createArticle.mockResolvedValueOnce({ id: 'a-1' })
		await wrapper.find('[data-testid="article-edit-save"]').trigger('click')
		await flushPromises()

		expect(articles.createArticle).toHaveBeenCalledWith(expect.objectContaining({ title: 'Waste collection', body: '## Body', language: 'nl', tags: [], links: [] }))
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'a-1' }]])
	})

	it('edits an existing article by its id and shows a refusal', async () => {
		const article = { '@self': { id: 'a-9' }, title: 'Old', language: 'en', tags: ['news'], links: [{ url: 'https://x.test' }] }
		const wrapper = open(ArticleEditModal, { article })
		expect(wrapper.find('h2').text()).toBe('Edit article')
		expect(wrapper.find('.labels').text()).toBe('news')
		expect(wrapper.find('[data-testid="article-edit-save"]').text()).toBe('Save changes')

		articles.updateArticle.mockRejectedValueOnce({ response: { data: { error: 'Slug taken' } } })
		await wrapper.find('[data-testid="article-edit-save"]').trigger('click')
		await flushPromises()
		expect(articles.updateArticle).toHaveBeenCalledWith('a-9', expect.objectContaining({ title: 'Old', language: 'en', links: [{ url: 'https://x.test' }] }))
		expect(wrapper.find('.note--error').text()).toBe('Slug taken')

		articles.updateArticle.mockRejectedValueOnce(new Error('offline'))
		await wrapper.find('[data-testid="article-edit-save"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note--error').text()).toBe('This article could not be saved.')

		wrapper.vm.languageOption = null
		expect(wrapper.vm.model.language).toBe('nl')
		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('takes the hero image from the file picker, and ignores a closed picker', async () => {
		const wrapper = open(ArticleEditModal, {})
		picker.pickNodes.mockResolvedValueOnce([{ path: '/Photos/hero.jpg' }])
		await button(wrapper, 'Browse…').trigger('click')
		await flushPromises()
		expect(wrapper.vm.model.heroImage).toBe('/Photos/hero.jpg')

		const { FilePickerClosed } = await import('@nextcloud/dialogs')
		picker.pickNodes.mockRejectedValueOnce(new FilePickerClosed())
		await wrapper.vm.openHeroPicker()
		expect(wrapper.vm.error).toBe('')

		const spy = vi.spyOn(console, 'error').mockImplementation(() => {})
		picker.pickNodes.mockRejectedValueOnce(new Error('no files app'))
		await wrapper.vm.openHeroPicker()
		await flushPromises()
		expect(wrapper.find('.note--error').text()).toBe('The file picker could not be opened.')
		spy.mockRestore()
	})
})

describe('SendMessageModal rendered', () => {
	const preflight = {
		channels: { sms: true, whatsapp: true },
		whatsappSessionOpen: false,
		consent: { sms: 'opted-in', whatsapp: 'unknown' },
		templates: [{ id: 'tp-1', externalId: 'welcome', language: 'nl', body: 'Hoi {{1}}' }],
	}

	it('switches channel, fills a saved reply and shows a refusal', async () => {
		const wrapper = open(SendMessageModal, { contactId: 'c-1', preflight })
		const [sms, whatsapp] = wrapper.findAll('.send-message__switch-option')
		await sms.trigger('click')
		expect(sms.attributes('aria-checked')).toBe('true')
		expect(wrapper.find('[data-testid="send-message-consent-state"]').text()).toBe('Consent for SMS: opted in')

		await wrapper.find('.saved-reply').trigger('click')
		expect(wrapper.vm.body).toBe('Saved text')

		http.post.mockResolvedValueOnce({ data: { status: 'no-provider' } })
		await button(wrapper, 'Send').trigger('click')
		await flushPromises()
		expect(wrapper.find('.send-message__result').text()).toBe('The server refused to send: no active provider is configured for this channel.')

		await whatsapp.trigger('click')
		expect(wrapper.find('[data-testid="send-message-consent"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('The 24-hour WhatsApp session window is closed')
		expect(wrapper.find('.saved-reply').exists()).toBe(false)
	})

	it('records consent and then offers the template with its parameters', async () => {
		const wrapper = open(SendMessageModal, { contactId: 'c-1', preflight, initialChannel: 'whatsapp' })
		const evidence = wrapper.findAll('[data-testid="send-message-consent"] input')[0]
		await evidence.setValue('Signed form')
		http.post.mockResolvedValueOnce({ data: { state: 'opted-in' } })
		await button(wrapper, 'Record consent').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="send-message-consent"]').exists()).toBe(false)

		wrapper.vm.templateId = 'tp-1'
		await flushPromises()
		expect(wrapper.find('.send-message__template-preview').text()).toBe('Hoi {{1}}')
		const params = wrapper.findAll('.send-message__pair input')
		expect(params).toHaveLength(1)
		await params[0].setValue('Anna')
		expect(wrapper.vm.templateParams).toEqual(['Anna'])
	})

	it('says when no provider is active', () => {
		const wrapper = open(SendMessageModal, { contactId: 'c-1', preflight: { channels: {} } })
		expect(wrapper.find('[data-testid="send-message-no-channel"]').exists()).toBe(true)
		expect(wrapper.findAll('.send-message__switch-option').every((b) => b.attributes('disabled') !== undefined)).toBe(true)
	})
})

describe('CtiDispositionModal', () => {
	it('shows the call facts and the escalation queue only for Escalated', async () => {
		const wrapper = open(CtiDispositionModal, {
			number: '+31612345678',
			clientName: 'Acme',
			durationSeconds: 125,
			recordedAs: 'CM-12',
			recordedHref: '/apps/pipelinq/tickets/cm-12',
			queueOptions: [{ value: 'q-1', label: 'Second line' }],
		})
		expect(wrapper.find('.dialog__eyebrow').text()).toBe('Conversation · 06 12 34 56 78')
		expect(wrapper.findAll('.cti-disposition__facts dt').map((d) => d.text())).toEqual(['Client', 'Duration', 'Recorded as'])
		expect(wrapper.findAll('.cti-disposition__facts dd').map((d) => d.text())).toEqual(['Acme', '2 minutes 5 seconds', 'CM-12'])
		expect(wrapper.find('.cti-disposition__facts a').attributes('href')).toBe('/apps/pipelinq/tickets/cm-12')
		expect(wrapper.findAll('.radio').map((r) => r.text())).toEqual(['Resolved', 'Callback', 'Escalated', 'Wrong number', 'No answer', 'Hung up'])
		expect(wrapper.find('.cti-disposition__queue').exists()).toBe(false)

		await wrapper.findAll('.radio input')[2].trigger('change')
		expect(wrapper.find('.cti-disposition__queue').exists()).toBe(true)
	})

	it('needs a subject and an outcome before it submits', async () => {
		const wrapper = open(CtiDispositionModal, { durationSeconds: 7 })
		expect(wrapper.findAll('.cti-disposition__facts dd').map((d) => d.text())).toEqual(['7 seconds'])
		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')).toBeUndefined()

		await wrapper.find('#cti-disposition-subject input').setValue('Invoice question')
		await wrapper.findAll('.radio input')[0].trigger('change')
		await wrapper.find('#cti-disposition-notes textarea').setValue('Explained the late fee')
		await wrapper.find('[data-testid="cti-disposition-submit"]').trigger('click')
		expect(wrapper.emitted('submit')).toEqual([[{ subject: 'Invoice question', outcome: 'resolved', notes: 'Explained the late fee', queue: '' }]])

		await wrapper.find('[data-testid="cti-disposition-cancel"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(open(CtiDispositionModal, {}).find('.cti-disposition__facts').exists()).toBe(false)
	})
})
