// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal documents dashboard (tabs, keyboard moves, downloads), the
 * social post composer, the article editor and the contract invoicing
 * section, each run against a plain component context with their services
 * doubled.
 *
 * @spec openspec/specs/customer-portal/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const picker = vi.hoisted(() => ({ pickNodes: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => {
	class FilePickerClosed extends Error {}
	const builder = {}
	for (const step of ['setMultiSelect', 'setMimeTypeFilter', 'allowDirectories', 'addButton']) {
		builder[step] = () => builder
	}
	builder.build = () => picker
	return {
		FilePickerClosed,
		getFilePickerBuilder: () => builder,
		showError: (m) => toasts.push(['error', m]),
		showSuccess: (m) => toasts.push(['success', m]),
	}
})
vi.mock('@nextcloud/dialogs/style.css', () => ({}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard'), NcSelect: stub('NcSelect'), NcTextArea: stub('NcTextArea'), NcTextField: stub('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog'), CnMarkdownEditor: stub('CnMarkdownEditor') }))
vi.mock('../../src/components/LabelSelect.vue', () => ({ default: stub('LabelSelect') }))
const portalApi = vi.hoisted(() => ({ invoices: vi.fn(), contracts: vi.fn(), orders: vi.fn(), signDocument: vi.fn() }))
vi.mock('../../src/portal/portalApi.js', () => ({ portalApi }))
const socialApi = vi.hoisted(() => ({ createPost: vi.fn(), fetchAccounts: vi.fn(), movePost: vi.fn(), updatePost: vi.fn() }))
vi.mock('../../src/services/socialApi.js', () => socialApi)
const articlesApi = vi.hoisted(() => ({ createArticle: vi.fn(), updateArticle: vi.fn() }))
vi.mock('../../src/services/articlesApi.js', () => articlesApi)

const { default: PortalDashboard } = await import('../../src/portal/views/PortalDashboard.vue')
const { default: SocialPostComposeModal } = await import('../../src/modals/SocialPostComposeModal.vue')
const { default: ArticleEditModal } = await import('../../src/modals/ArticleEditModal.vue')
const { default: ContractInvoicingSection } = await import('../../src/views/contracts/ContractInvoicingSection.vue')
const { FilePickerClosed } = await import('@nextcloud/dialogs')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values (plain or with a setter) as properties.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), $nextTick: (fn) => fn(), t: globalThis.t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		const get = typeof def === 'function' ? def : def.get
		Object.defineProperty(ctx, name, { get: () => get.call(ctx), set: def.set ? (v) => def.set.call(ctx, v) : undefined, configurable: true })
	}
	return ctx
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	toasts.length = 0
	picker.pickNodes.mockReset()
	for (const fn of [http.get, http.post, ...Object.values(portalApi), ...Object.values(socialApi), ...Object.values(articlesApi)]) {
		fn.mockReset()
	}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
})

describe('PortalDashboard', () => {
	const dashboard = (features) => context(PortalDashboard, { features: features ?? ['invoices', 'contracts', 'orders'] })

	it('shows the enabled tabs and opens the first', async () => {
		const d = dashboard(['orders', 'contracts'])
		portalApi.contracts.mockResolvedValueOnce({ items: [{ id: 'k1' }] })
		PortalDashboard.mounted.call(d)
		await tick()
		expect([d.availableTabs.map((tab) => tab.key), d.activeTab, d.rows, d.tableCaption]).toEqual([['contracts', 'orders'], 'contracts', [{ id: 'k1' }], 'Contracts'])
		const none = dashboard([])
		PortalDashboard.mounted.call(none)
		expect([none.availableTabs, portalApi.invoices.mock.calls.length, portalApi.contracts.mock.calls.length, portalApi.orders.mock.calls.length]).toEqual([[], 0, 1, 0])
		none.activeTab = 'odd'
		expect(none.tableCaption).toBe('My documents')
	})

	it('says when a tab cannot be loaded', async () => {
		const d = dashboard()
		portalApi.invoices.mockRejectedValueOnce(new Error('Session ended'))
		await d.select('invoices')
		portalApi.orders.mockRejectedValueOnce({})
		const e = dashboard()
		await e.select('orders')
		portalApi.orders.mockResolvedValueOnce({})
		const f = dashboard()
		await f.select('orders')
		expect([d.error, e.error, e.rows, f.rows, f.loading]).toEqual(['Session ended', 'Could not load data.', [], [], false])
	})

	it('moves between tabs with the arrow, Home and End keys and wraps around', () => {
		const d = dashboard()
		portalApi.invoices.mockResolvedValue({ items: [] })
		portalApi.contracts.mockResolvedValue({ items: [] })
		portalApi.orders.mockResolvedValue({ items: [] })
		document.body.innerHTML = '<button id="portal-tab-orders"></button><button id="portal-tab-contracts"></button>'
		const press = (key, from) => {
			const event = { key, preventDefault: vi.fn() }
			d.onTabKeydown(event, from)
			return [d.activeTab, event.preventDefault.mock.calls.length]
		}
		expect(press('ArrowLeft', 'invoices')).toEqual(['orders', 1])
		expect(document.activeElement.id).toBe('portal-tab-orders')
		expect(press('ArrowRight', 'orders')).toEqual(['invoices', 1])
		expect(press('End', 'invoices')).toEqual(['orders', 1])
		expect(press('Home', 'orders')).toEqual(['invoices', 1])
		expect(press('ArrowRight', 'invoices')).toEqual(['contracts', 1])
		expect(press('Tab', 'contracts')).toEqual(['contracts', 0])
		expect(press('ArrowRight', 'unknown')).toEqual(['contracts', 0])
	})

	it('downloads a document through a signed link', async () => {
		const d = dashboard()
		const open = vi.fn()
		vi.stubGlobal('open', open)
		portalApi.signDocument.mockResolvedValue({ downloadUrl: '/dl/1' })
		d.activeTab = 'invoices'
		await d.download({ id: 'i1' })
		d.activeTab = 'orders'
		await d.download({ id: 'o1' })
		expect(portalApi.signDocument.mock.calls).toEqual([['i1', 'invoice'], ['o1', 'order']])
		expect(open).toHaveBeenCalledWith('/index.php/apps/pipelinq/dl/1', '_blank')
		portalApi.signDocument.mockRejectedValueOnce({})
		await d.download({ id: 'o2' })
		expect(d.error).toBe('Could not download the document.')
		expect([d.downloadLabel({ invoiceNumber: 'F-1' }), d.downloadLabel({ orderNumber: 'O-2' }), d.downloadLabel({})]).toEqual(['Download F-1', 'Download O-2', 'Download '])
	})
})

describe('SocialPostComposeModal', () => {
	const accounts = [{ id: 'a1', network: 'mastodon', displayName: 'Gem' }, { uuid: 'a2', network: 'mastodon', handle: '@b' }, { id: 'a3', network: 'linkedin', handle: 'li' }, { id: 'a4', network: 'x', active: false }]

	it('starts empty, or from the post being edited', () => {
		const fresh = context(SocialPostComposeModal, { post: null })
		expect([fresh.modalTitle, fresh.chosenAccounts, fresh.model.media]).toEqual(['Compose a post', [], []])
		const edit = context(SocialPostComposeModal, { post: { title: 'T', body: 'B', accountIds: ['a1'], variants: { mastodon: { body: 'm' } }, media: [1] } })
		expect([edit.modalTitle, edit.chosenAccounts, edit.model.body, edit.variantBody('mastodon'), edit.variantBody('x')]).toEqual(['Edit post', ['a1'], 'B', 'm', ''])
	})

	it('offers the active accounts and works out the chosen networks', async () => {
		socialApi.fetchAccounts.mockResolvedValueOnce({ data: accounts })
		const m = context(SocialPostComposeModal, { post: null })
		SocialPostComposeModal.mounted.call(m)
		await tick()
		expect(m.accountOptions).toEqual([{ value: 'a1', label: 'Gem (mastodon)' }, { value: 'a2', label: '@b (mastodon)' }, { value: 'a3', label: 'li (linkedin)' }])
		m.chosenAccounts = ['a1', 'a2', 'a3']
		expect(m.chosenNetworks).toEqual(['mastodon', 'linkedin'])
		expect([m.canSubmitPost, m.problems]).toEqual([false, ['A post needs something to say.']])
		m.model.body = 'Hello'
		expect([m.canSubmitPost, m.fits.length]).toEqual([true, 2])
		socialApi.fetchAccounts.mockRejectedValueOnce(new Error('x'))
		await m.loadAccounts()
		expect(m.error).toBe('The accounts could not be loaded.')
	})

	it('keeps a variant only while it has text', () => {
		const m = context(SocialPostComposeModal, { post: null })
		m.setVariantBody('mastodon', 'Short')
		expect(m.model.variants).toEqual({ mastodon: { body: 'Short' } })
		m.setVariantBody('mastodon', '  ')
		expect(m.model.variants).toEqual({})
	})

	it('creates or updates the post, submitting it for review on request', async () => {
		const fresh = context(SocialPostComposeModal, { post: null })
		fresh.chosenAccounts = ['a1']
		socialApi.createPost.mockResolvedValueOnce({ id: 'p1' })
		socialApi.movePost.mockResolvedValueOnce({ id: 'p1', status: 'review' })
		await fresh.save(true)
		expect([socialApi.createPost.mock.calls[0][0].accountIds, socialApi.movePost.mock.calls[0], fresh.$emit.mock.calls[0]]).toEqual([['a1'], ['p1', 'submit'], ['saved', { id: 'p1', status: 'review' }]])
		const edit = context(SocialPostComposeModal, { post: { uuid: 'p2' } })
		socialApi.updatePost.mockResolvedValueOnce({ uuid: 'p2' })
		await edit.save(false)
		expect([socialApi.updatePost.mock.calls[0][0], socialApi.movePost.mock.calls.length, edit.$emit.mock.calls[0][1]]).toEqual(['p2', 1, { uuid: 'p2' }])
		socialApi.createPost.mockResolvedValueOnce(null)
		await fresh.save(true)
		expect(socialApi.movePost).toHaveBeenCalledTimes(1)
	})

	it('says why a post could not be saved', async () => {
		const m = context(SocialPostComposeModal, { post: null })
		socialApi.createPost.mockRejectedValueOnce({ response: { data: { error: 'Too long' } } })
		await m.save(false)
		const n = context(SocialPostComposeModal, { post: null })
		socialApi.createPost.mockRejectedValueOnce({})
		await n.save(false)
		expect([m.error, n.error, n.saving, n.$emit.mock.calls.length]).toEqual(['Too long', 'The post could not be saved.', false, 0])
	})
})

describe('ArticleEditModal', () => {
	it('starts a Dutch article, or copies the one being edited', () => {
		const fresh = context(ArticleEditModal, { article: null })
		expect([fresh.isEditing, fresh.modalTitle, fresh.model.language, fresh.canSave, fresh.languageOptions.length]).toEqual([false, 'New article', 'nl', false, 2])
		const links = [{ url: 'https://x' }]
		const edit = context(ArticleEditModal, { article: { id: 'a1', title: 'News', tags: ['x'], links, language: 'en' } })
		expect([edit.isEditing, edit.modalTitle, edit.languageOption, edit.tags, edit.canSave]).toEqual([true, 'Edit article', 'en', ['x'], true])
		edit.links[0].url = 'changed'
		expect(links[0].url).toBe('https://x')
		edit.languageOption = null
		expect(edit.model.language).toBe('nl')
		edit.saving = true
		expect(edit.canSave).toBe(false)
	})

	it('creates or updates the article with its tags and links', async () => {
		const fresh = context(ArticleEditModal, { article: null })
		await fresh.save()
		expect(articlesApi.createArticle).not.toHaveBeenCalled()
		fresh.model.title = 'Spring'
		fresh.tags = ['t']
		articlesApi.createArticle.mockResolvedValueOnce({ id: 'new' })
		await fresh.save()
		expect([articlesApi.createArticle.mock.calls[0][0].tags, articlesApi.createArticle.mock.calls[0][0].title, fresh.$emit.mock.calls[0]]).toEqual([['t'], 'Spring', ['saved', { id: 'new' }]])
		const edit = context(ArticleEditModal, { article: { '@self': { id: 'self1' }, title: 'Old' } })
		await edit.save()
		expect(articlesApi.updateArticle.mock.calls[0][0]).toBe('self1')
		articlesApi.updateArticle.mockRejectedValueOnce({ response: { data: { error: 'Slug taken' } } })
		await edit.save()
		articlesApi.updateArticle.mockRejectedValueOnce({})
		const other = context(ArticleEditModal, { article: { uuid: 'u1', title: 'X' } })
		await other.save()
		expect([edit.error, other.error, articlesApi.updateArticle.mock.calls[2][0], other.saving]).toEqual(['Slug taken', 'This article could not be saved.', 'u1', false])
	})

	it('takes the hero image from the file picker, ignoring a closed picker', async () => {
		const m = context(ArticleEditModal, { article: null })
		picker.pickNodes.mockResolvedValueOnce([{ path: '/Photos/hero.jpg' }])
		await m.openHeroPicker()
		expect(m.model.heroImage).toBe('/Photos/hero.jpg')
		picker.pickNodes.mockResolvedValueOnce({ path: '/Photos/two.jpg' })
		await m.openHeroPicker()
		picker.pickNodes.mockResolvedValueOnce([])
		await m.openHeroPicker()
		expect(m.model.heroImage).toBe('/Photos/two.jpg')
		picker.pickNodes.mockRejectedValueOnce(new FilePickerClosed())
		await m.openHeroPicker()
		expect(m.error).toBe('')
		picker.pickNodes.mockRejectedValueOnce(new Error('boom'))
		await m.openHeroPicker()
		expect(m.error).toBe('The file picker could not be opened.')
	})
})

describe('ContractInvoicingSection', () => {
	const section = (props = {}) => context(ContractInvoicingSection, { contractId: '', cnSectionContext: null, ...props })

	it('reads whether this contract can go to invoicing', async () => {
		const s = section({ cnSectionContext: { objectId: 'k1' } })
		http.get.mockResolvedValueOnce({ data: { available: 1, canSend: 1 } })
		s.lastInvoiceReference = 'old'
		ContractInvoicingSection.watch.resolvedId.handler.call(s)
		await tick()
		expect([http.get.mock.calls[0][0], s.availability, s.lastInvoiceReference, s.showSendButton, s.hasContent]).toEqual(['/index.php/apps/pipelinq/api/handoff/contract/k1/availability', { available: true, status: '', canSend: true }, '', true, true])
		http.get.mockRejectedValueOnce(new Error('x'))
		await s.loadAvailability()
		expect([s.showSendButton, s.hasContent]).toEqual([false, false])
		const none = section()
		await none.loadAvailability()
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('sends the contract once and shows the invoice reference', async () => {
		const s = section({ contractId: 'k1' })
		http.post.mockResolvedValueOnce({ data: { invoiceReference: 'INV-9' } })
		await s.sendToInvoicing()
		expect([http.post.mock.calls[0][0], s.lastInvoiceReference, s.hasContent, toasts[0], s.$emit.mock.calls[0]]).toEqual(['/index.php/apps/pipelinq/api/handoff/contract/k1/send-to-invoicing', 'INV-9', true, ['success', 'Contract sent to invoicing.'], ['sent', { invoiceReference: 'INV-9' }]])
		s.busy = true
		await s.sendToInvoicing()
		await section().sendToInvoicing()
		expect(http.post).toHaveBeenCalledTimes(1)
	})

	it('explains a refused hand-off and reloads when it is no longer allowed', async () => {
		const s = section({ contractId: 'k1' })
		http.get.mockResolvedValue({ data: { canSend: false } })
		http.post.mockRejectedValueOnce({ response: { data: { status: 'invalid-status' } } })
		await s.sendToInvoicing()
		expect([toasts[0], http.get.mock.calls.length, s.busy]).toEqual([['error', 'Sending to invoicing is no longer available for this contract.'], 1, false])
		http.post.mockRejectedValueOnce({ response: { data: { status: 'handoff-failed', reason: 'No debtor' } } })
		await s.sendToInvoicing()
		http.post.mockRejectedValueOnce({ response: { data: { status: 'handoff-failed' } } })
		await s.sendToInvoicing()
		http.post.mockRejectedValueOnce({})
		await s.sendToInvoicing()
		expect(toasts.slice(1).map((e) => e[1])).toEqual(['Could not send this contract to invoicing: No debtor', 'Could not send this contract to invoicing: unknown error', 'Could not send this contract to invoicing.'])
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('copies the invoice reference, or says it could not', async () => {
		const s = section({ contractId: 'k1' })
		s.lastInvoiceReference = 'INV-9'
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		await s.copyReference()
		writeText.mockRejectedValueOnce(new Error('denied'))
		await s.copyReference()
		expect([writeText.mock.calls[0], toasts]).toEqual([['INV-9'], [['success', 'Invoice reference copied.'], ['error', 'Could not copy the invoice reference.']]])
	})
})
