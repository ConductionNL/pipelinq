// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CTI settings (load, save, connection test), the export run detail
 * (status, retry), the subscription manager (closing one or all, the
 * preference link) and the POS tender type and tender dialogs, each run
 * against a plain component context with their services doubled.
 *
 * @spec openspec/specs/pos/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDetailCard: stub('CnDetailCard'), CnDetailPage: stub('CnDetailPage'), CnDialog: stub('CnDialog'), CnStatusBadge: stub('CnStatusBadge') }))
const cti = vi.hoisted(() => ({ getConfig: vi.fn(), testConnection: vi.fn(), updateConfig: vi.fn() }))
vi.mock('../../src/services/ctiApi.js', () => cti)
const exportApi = vi.hoisted(() => ({ getRun: vi.fn(), retryRun: vi.fn() }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi }))
const mailing = vi.hoisted(() => ({ fetchPreferenceLink: vi.fn(), unsubscribeContact: vi.fn() }))
vi.mock('../../src/services/mailingListApi.js', () => mailing)

const { default: CtiSettings } = await import('../../src/views/settings/CtiSettings.vue')
const { default: ExportRunDetail } = await import('../../src/views/export/ExportRunDetail.vue')
const { default: SubscriptionManageModal } = await import('../../src/modals/SubscriptionManageModal.vue')
const { default: PosTenderTypeFormDialog } = await import('../../src/modals/PosTenderTypeFormDialog.vue')
const { default: AddTenderDialog } = await import('../../src/modals/AddTenderDialog.vue')

/**
 * A component context: props and extras, data, computed values as getters
 * and methods bound.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), $router: { push: vi.fn() }, t: globalThis.t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	for (const fn of [...Object.values(http), ...Object.values(cti), ...Object.values(exportApi), ...Object.values(mailing)]) {
		fn.mockReset()
	}
})

describe('CtiSettings', () => {
	it('merges the stored config over the defaults, and says when it cannot load', async () => {
		const s = context(CtiSettings)
		cti.getConfig.mockResolvedValueOnce({ platform: 'asterisk' })
		await CtiSettings.mounted.call(s)
		expect([s.config.platform, s.config.default_country_code]).toEqual(['asterisk', 'NL'])
		cti.getConfig.mockResolvedValueOnce(null)
		await CtiSettings.mounted.call(s)
		cti.getConfig.mockRejectedValueOnce(new Error('timeout'))
		await CtiSettings.mounted.call(s)
		cti.getConfig.mockRejectedValueOnce({})
		await CtiSettings.mounted.call(s)
		expect(toasts).toEqual([['error', 'Failed to load CTI config: timeout'], ['error', 'Failed to load CTI config: network error']])
		expect(s.platformOptions.map((o) => o.value)).toEqual(['callvoip', 'ringcentral', 'asterisk', 'other'])
		expect(s.authMethodOptions.map((o) => o.value)).toEqual(['basic', 'oauth', 'api_key', 'webhook-secret'])
	})

	it('saves the config and keeps what the server returns', async () => {
		const s = context(CtiSettings)
		cti.updateConfig.mockResolvedValueOnce({ api_base_url: 'https://pbx' })
		await s.save()
		expect([cti.updateConfig.mock.calls[0][0].platform, s.config.api_base_url, s.saving, toasts[0]]).toEqual(['callvoip', 'https://pbx', false, ['success', 'CTI configuration saved.']])
		cti.updateConfig.mockRejectedValueOnce(new Error('403'))
		await s.save()
		expect(toasts[1]).toEqual(['error', 'Failed to save CTI config: 403'])
	})

	it('reports the connection test', async () => {
		const s = context(CtiSettings)
		cti.testConnection.mockResolvedValueOnce({ ok: true, platform: 'CallVoip' })
		await s.test()
		expect(s.status).toBe('Connection OK (CallVoip)')
		cti.testConnection.mockResolvedValueOnce({ ok: false, message: 'Bad secret' })
		await s.test()
		expect(s.status).toBe('Connection failed: Bad secret')
		cti.testConnection.mockRejectedValueOnce({})
		await s.test()
		expect([s.status, s.testing]).toEqual(['Connection test errored: unknown', false])
	})
})

describe('ExportRunDetail', () => {
	const detail = (props = {}, extra = {}) => context(ExportRunDetail, { exportRunId: null, id: null, ...props }, extra)

	it('loads the run and its snapshots, and nothing without an id', async () => {
		const none = detail()
		await ExportRunDetail.mounted.call(none)
		expect(exportApi.getRun).not.toHaveBeenCalled()
		const d = detail({}, { $route: { params: { id: 'run1' } } })
		exportApi.getRun.mockResolvedValueOnce({ run: { status: 'partial', fileManifestJson: [{ f: 1 }] }, snapshots: [{ s: 1 }] })
		await d.load()
		expect([exportApi.getRun.mock.calls[0], d.manifest, d.snapshots, d.statusLabel, d.badgeStatus, d.canRetry]).toEqual([['run1'], [{ f: 1 }], [{ s: 1 }], 'Partial', 'warning', true])
		exportApi.getRun.mockResolvedValueOnce({})
		await d.load()
		expect([d.run, d.manifest, d.statusLabel, d.badgeStatus, d.canRetry]).toEqual([{}, [], 'Unknown', 'info', false])
		exportApi.getRun.mockRejectedValueOnce(new Error('Gone'))
		await d.load()
		exportApi.getRun.mockRejectedValueOnce({})
		await d.load()
		expect(toasts).toEqual([['error', 'Gone'], ['error', 'Could not load the run']])
	})

	it('labels each status and allows a retry only after a problem', () => {
		const d = detail({ id: 'r' })
		const states = ['pending', 'running', 'succeeded', 'partial', 'failed', 'skipped_overlap', 'odd']
		const seen = states.map((status) => {
			d.run = { status }
			return [d.statusLabel, d.badgeStatus, d.canRetry]
		})
		expect(seen).toEqual([
			['Queued', 'info', false], ['Running', 'info', false], ['Succeeded', 'success', false], ['Partial', 'warning', true],
			['Failed', 'error', true], ['Skipped (overlap)', 'warning', true], ['odd', 'info', false],
		])
	})

	it('queues a new run and goes back to the list', async () => {
		const d = detail({ exportRunId: 'r1' })
		await d.retry()
		expect([exportApi.retryRun.mock.calls[0], toasts[0], d.$router.push.mock.calls[0][0], d.busy]).toEqual([['r1'], ['success', 'A new run has been queued'], { name: 'ExportRuns' }, false])
		exportApi.retryRun.mockRejectedValueOnce({})
		await d.retry()
		expect(toasts[1]).toEqual(['error', 'Could not retry the run'])
	})
})

describe('SubscriptionManageModal', () => {
	const modal = () => context(SubscriptionManageModal, { contactId: 'c1', subscriptions: [] })

	it('labels a subscription and allows removing it until it is closed', () => {
		const m = modal()
		expect([m.canRemove({ state: 'active' }), m.canRemove({ state: 'unsubscribed' }), m.canRemove(null)]).toEqual([true, false, false])
		expect([m.removeLabel({ listId: 'news' }), m.removeLabel(null), m.stateLabel({ state: 'odd' })]).toEqual(['Remove from news', 'Remove from ', 'Unknown'])
	})

	it('closes one list or all of them as a CRM request', async () => {
		const m = modal()
		mailing.unsubscribeContact.mockResolvedValueOnce({ count: 1 }).mockResolvedValueOnce({})
		await m.removeOne({ listId: 'news' })
		expect([mailing.unsubscribeContact.mock.calls[0], m.message, m.messageType]).toEqual([['c1', 'news', 'crm-request'], '1 subscription(s) closed. The withdrawal is recorded.', 'success'])
		await m.removeAll()
		expect([mailing.unsubscribeContact.mock.calls[1], m.message, m.$emit.mock.calls.length]).toEqual([['c1', '', 'crm-request'], '0 subscription(s) closed. The withdrawal is recorded.', 2])
		mailing.unsubscribeContact.mockRejectedValueOnce(new Error('x'))
		await m.removeAll()
		expect([m.message, m.messageType, m.busy, m.$emit.mock.calls.length]).toEqual(['That did not work. Nothing was changed.', 'error', false, 2])
	})

	it('makes the preference link, or says it could not', async () => {
		const m = modal()
		mailing.fetchPreferenceLink.mockResolvedValueOnce('https://prefs')
		await m.copyPreferenceLink()
		expect([mailing.fetchPreferenceLink.mock.calls[0], m.preferenceUrl, m.message]).toEqual([['c1'], 'https://prefs', ''])
		mailing.fetchPreferenceLink.mockRejectedValueOnce(new Error('x'))
		await m.copyPreferenceLink()
		expect([m.message, m.messageType, m.busy]).toEqual(['The link could not be made.', 'error', false])
	})
})

describe('PosTenderTypeFormDialog', () => {
	const dialog = (tenderType = null) => context(PosTenderTypeFormDialog, { tenderType })

	it('starts a new active tender type, or copies the given one', () => {
		const fresh = dialog()
		expect([fresh.isNew, fresh.form.isActive, fresh.form.sortOrder, fresh.canSave]).toEqual([true, true, 0, false])
		const given = dialog({ '@self': { id: 't1' }, name: 'Cash', code: 'cash', glAccount: '1000', isActive: 0, requiresPin: 1, sortOrder: '3' })
		expect([given.isNew, given.form.code, given.form.isActive, given.form.requiresPin, given.form.sortOrder, given.canSave]).toEqual([false, 'CASH', false, true, 3, true])
		expect(dialog({ name: 'No id' }).isNew).toBe(true)
	})

	it('creates or updates with the code in capitals', async () => {
		const fresh = dialog()
		Object.assign(fresh.form, { name: 'Card', code: 'pin', glAccount: '1100' })
		await fresh.submit()
		expect(http.post.mock.calls[0]).toEqual(['/index.php/apps/pipelinq/api/pos/tender-types', expect.objectContaining({ code: 'PIN', name: 'Card' })])
		expect([toasts[0], fresh.$emit.mock.calls[0]]).toEqual([['success', 'Tender type created'], ['saved']])
		const given = dialog({ id: 't1', name: 'Cash', code: 'CASH', glAccount: '1000' })
		await given.submit()
		expect([http.put.mock.calls[0][0], toasts[1]]).toEqual(['/index.php/apps/pipelinq/api/pos/tender-types/t1', ['success', 'Tender type updated']])
	})

	it('shows why a tender type could not be saved', async () => {
		const d = dialog()
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Code in use' } } })
		await d.submit()
		http.post.mockRejectedValueOnce({})
		await d.submit()
		expect([d.errorMessage, toasts, d.saving, d.$emit.mock.calls.length]).toEqual(['Failed to save tender type', [['error', 'Code in use'], ['error', 'Failed to save tender type']], false, 0])
	})
})

describe('AddTenderDialog', () => {
	const types = [{ id: 'cash' }, { '@self': { id: 'gift' }, requiresReference: true }]
	const dialog = (props = {}) => context(AddTenderDialog, { transactionId: 'tx 1', transactionTotal: 0, remaining: 0, tenderTypes: types, ...props })

	it('proposes the remaining amount, rounded to cents', () => {
		const d = dialog({ remaining: 12.345 })
		AddTenderDialog.mounted.call(d)
		expect([d.suggestedAmount, d.amount]).toEqual([12.35, 12.35])
		const paid = dialog()
		AddTenderDialog.mounted.call(paid)
		expect(paid.amount).toBe(0)
	})

	it('needs a tender type, an amount and a reference where the type asks one', () => {
		const d = dialog()
		expect([d.selectedType, d.canSubmit]).toEqual([null, false])
		d.selected = 'cash'
		d.amount = 0.009
		expect(d.canSubmit).toBe(false)
		d.amount = 5
		expect([d.selectedType.id, d.requiresReference, d.canSubmit]).toEqual(['cash', false, true])
		d.selected = 'gift'
		d.reference = '  '
		expect([d.requiresReference, d.canSubmit]).toEqual([true, false])
		d.reference = 'GC-1'
		expect(d.canSubmit).toBe(true)
		d.selected = 'unknown'
		expect(d.selectedType).toBeNull()
	})

	it('adds the tender to the transaction, or shows why not', async () => {
		const d = dialog()
		Object.assign(d, { selected: 'gift', amount: '7.456', reference: ' GC-1 ' })
		await d.submit()
		expect(http.post.mock.calls[0]).toEqual(['/index.php/apps/pipelinq/api/pos-transactions/tx 1/tenders', { tenderType: 'gift', amount: 7.46, reference: 'GC-1' }])
		expect([toasts[0], d.$emit.mock.calls[0]]).toEqual([['success', 'Tender added'], ['added']])
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Amount exceeds the remainder' } } })
		await d.submit()
		http.post.mockRejectedValueOnce({})
		d.reference = null
		await d.submit()
		expect([d.errorMessage, toasts.slice(1).map((e) => e[1]), d.saving]).toEqual(['Failed to add tender', ['Amount exceeds the remainder', 'Failed to add tender'], false])
		expect(http.post.mock.calls[2][1].reference).toBe('')
	})
})
