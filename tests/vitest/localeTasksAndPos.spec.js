// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The locale formatters, the task helpers (labels, overdue, the default
 * deadline, group and assignee lookups), the POS staff session and the POS
 * customer, billing hand-off and bookkeeping calls.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text, t: (app, text) => text }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'usd' }) }))

const locale = await import('../../src/services/localeUtils.js')
const tasks = await import('../../src/services/taskUtils.js')
const { usePosSessionStore } = await import('../../src/store/modules/posSessionStore.js')
const customers = await import('../../src/services/posCustomerApi.js')
const billing = await import('../../src/services/billingHandoffApi.js')
const { raiseJournalEntry } = await import('../../src/services/posBookkeepingApi.js')

const P = '/index.php/apps/pipelinq'

beforeEach(() => {
	setActivePinia(createPinia())
	for (const fn of Object.values(http)) {
		fn.mockReset()
		fn.mockResolvedValue({ data: {} })
	}
	globalThis.OC = { requestToken: 'tok', getLocale: () => 'en_GB', getCurrentUser: () => ({ uid: 'a b' }) }
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('locale formatters', () => {
	it('reads the user locale as a language tag, defaulting to Dutch', () => {
		expect(locale.getUserLocale()).toBe('en-GB')
		OC.getLocale = () => 'de'
		expect(locale.getUserLocale()).toBe('de')
		OC.getLocale = () => ''
		expect(locale.getUserLocale()).toBe('nl-NL')
		delete globalThis.OC
		expect(locale.getUserLocale()).toBe('nl-NL')
	})

	it('formats amounts in the reporting currency and numbers, zero for nothing', () => {
		expect([locale.formatCurrency(1234.567), locale.formatCurrency('', 'EUR'), locale.formatCurrency('x'), locale.formatCurrency(null)]).toEqual(['USD 1,234.57', 'EUR 0', 'USD 0', 'USD 0'])
		expect([locale.formatNumber(1234), locale.formatNumber(undefined), locale.formatNumber('nope')]).toEqual(['1,234', '0', '0'])
	})

	it('formats dates and timestamps, keeping what it cannot read', () => {
		expect([locale.formatDate('2026-05-15'), locale.formatDateFull('2026-05-15'), locale.formatDate('')]).toEqual(['15 May', '15 May 2026', ''])
		expect([locale.formatLocalTimestamp(''), locale.formatLocalTimestamp('garbage')]).toEqual(['—', 'garbage'])
		expect(locale.formatLocalTimestamp('2026-05-15T10:00:00Z')).toBe(new Date('2026-05-15T10:00:00Z').toLocaleString())
	})

	it('converts between date input strings and local dates', () => {
		const date = locale.toDateObject('2026-05-15T23:00:00Z')
		expect([date.getFullYear(), date.getMonth(), date.getDate()]).toEqual([2026, 4, 15])
		expect([locale.toDateObject(''), locale.toDateObject('2026-05')]).toEqual([null, null])
		expect([locale.toDateInputString(new Date(2026, 0, 5)), locale.toDateInputString(new Date('x')), locale.toDateInputString('2026-01-05')]).toEqual(['2026-01-05', null, null])
	})
})

describe('task helpers', () => {
	it('labels types, statuses and priorities, keeping unknown values', () => {
		expect([tasks.getTaskTypeLabel('terugbelverzoek'), tasks.getTaskTypeLabel('other'), tasks.getTaskTypeLabel('')]).toEqual(['Callback request', 'other', '-'])
		expect([tasks.getTaskStatusLabel('in_behandeling'), tasks.getTaskStatusLabel(null)]).toEqual(['In progress', '-'])
		expect([tasks.getTaskPriorityLabel('hoog'), tasks.getTaskPriorityLabel('x')]).toEqual(['High', 'x'])
		expect(tasks.TASK_PRIORITY_ORDER.hoog < tasks.TASK_PRIORITY_ORDER.laag).toBe(true)
	})

	it('calls an open task overdue after its deadline', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		expect([
			tasks.isTaskOverdue({ deadline: '2026-05-15T11:00:00Z' }),
			tasks.isTaskOverdue({ deadline: '2026-05-15T13:00:00Z' }),
			tasks.isTaskOverdue({ deadline: '2026-05-01', status: 'completed' }),
			tasks.isTaskOverdue({ deadline: '2026-05-01', status: 'expired' }),
			tasks.isTaskOverdue({}),
		]).toEqual([true, false, false, false, false])
	})

	it('sets the default deadline on the next working day at five', () => {
		const deadlineOn = (iso) => {
			vi.useFakeTimers({ now: new Date(iso), toFake: ['Date'] })
			const value = new Date(tasks.getDefaultDeadline() + 'Z')
			vi.useRealTimers()
			return value
		}
		const local = (y, m, d) => new Date(y, m, d, 17, 0, 0, 0)
		const iso = (date) => date.toISOString().slice(0, 16)
		expect(iso(deadlineOn(new Date(2026, 4, 14, 9)))).toBe(iso(local(2026, 4, 15)))
		expect(iso(deadlineOn(new Date(2026, 4, 15, 9)))).toBe(iso(local(2026, 4, 18)))
		expect(iso(deadlineOn(new Date(2026, 4, 16, 9)))).toBe(iso(local(2026, 4, 18)))
	})

	it('reads the user groups, empty on any failure', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ ocs: { data: { groups: ['admin'] } } }) }))
		vi.stubGlobal('fetch', fetchSpy)
		expect(await tasks.fetchUserGroups()).toEqual(['admin'])
		expect(fetchSpy.mock.calls[0][0]).toBe('/ocs/v2.php/cloud/users/a%20b/groups')
		fetchSpy.mockResolvedValueOnce({ ok: false })
		expect(await tasks.fetchUserGroups()).toEqual([])
		fetchSpy.mockRejectedValueOnce(new Error('x'))
		expect(await tasks.fetchUserGroups()).toEqual([])
	})

	it('finds users then groups as assignees, exact matches first', async () => {
		const sharees = { exact: { users: [{ label: 'Ann', value: { shareWith: 'ann' } }], groups: [] }, users: [{ value: { shareWith: 'bob' } }], groups: [{ label: 'Sales' }] }
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ ocs: { data: sharees } }) }))
		vi.stubGlobal('fetch', fetchSpy)
		expect(await tasks.searchAssignees('a n')).toEqual([
			{ id: 'ann', label: 'Ann', type: 'user' },
			{ id: 'bob', label: 'bob', type: 'user' },
			{ id: 'Sales', label: 'Sales', type: 'group' },
		])
		expect(fetchSpy.mock.calls[0][0]).toBe('/ocs/v2.php/apps/files_sharing/api/v1/sharees?search=a%20n&itemType=file&perPage=20&format=json')
		expect(await tasks.searchAssignees('')).toEqual([])
		fetchSpy.mockResolvedValueOnce({ ok: true, json: async () => ({ ocs: { data: {} } }) })
		expect(await tasks.searchAssignees('x')).toEqual([])
		fetchSpy.mockResolvedValueOnce({ ok: false })
		expect(await tasks.searchAssignees('x')).toEqual([])
		fetchSpy.mockRejectedValueOnce(new Error('x'))
		expect(await tasks.searchAssignees('x')).toEqual([])
		expect(fetchSpy).toHaveBeenCalledTimes(4)
	})
})

describe('POS staff session', () => {
	it('is active for a staff member until it expires', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const s = usePosSessionStore()
		expect(s.isSessionActive).toBe(false)
		s.openSession({ staffId: 7, displayName: 'Kim', permissions: { canVoid: true }, expiresAt: '2026-05-15T13:00:00Z' })
		expect([s.staffId, s.displayName, s.permissions.canVoid, s.permissions.canRefund, s.isSessionActive]).toEqual(['7', 'Kim', true, false, true])
		s.expiresAt = '2026-05-15T12:00:00Z'
		expect(s.isSessionActive).toBe(false)
		s.expiresAt = 'unreadable'
		expect(s.isSessionActive).toBe(true)
		s.expiresAt = ''
		expect(s.isSessionActive).toBe(true)
		s.closeSession()
		expect([s.staffId, s.permissions.canVoid, s.isSessionActive]).toEqual(['', false, false])
		s.openSession(null)
		expect([s.staffId, s.expiresAt, s.permissions.maxDiscountPercent]).toEqual(['', '', 0])
	})
})

describe('POS customers, billing and bookkeeping', () => {
	it('searches customers and their history, empty for an odd answer', async () => {
		http.get.mockResolvedValueOnce({ data: { customers: [{ id: 1 }] } })
		expect(await customers.searchCustomers('jan')).toEqual([{ id: 1 }])
		expect(http.get).toHaveBeenLastCalledWith(`${P}/api/pos-customers/search`, { params: { query: 'jan', limit: 20 } })
		expect(await customers.getCustomerHistory('c 1', 5)).toEqual([])
		expect(http.get).toHaveBeenLastCalledWith(`${P}/api/pos-customers/c%201/history`, { params: { limit: 5 } })
		http.get.mockResolvedValueOnce({ data: { history: [1, 2] } })
		expect(await customers.getCustomerHistory('c1')).toEqual([1, 2])
	})

	it('attaches and detaches a customer and reads the settings', async () => {
		http.post.mockResolvedValueOnce({ data: { transaction: { id: 't' } } })
		expect(await customers.attachCustomer('t 1', 'c1')).toEqual({ id: 't' })
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/pos-transactions/t%201/customer`, { customer: 'c1', marketingConsent: false })
		expect(await customers.detachCustomer('t1')).toBeNull()
		expect(http.delete).toHaveBeenCalledWith(`${P}/api/pos-transactions/t1/customer`)
		expect([await customers.getCustomerSettings(), await customers.updateCustomerSettings({ a: 1 })]).toEqual([{}, {}])
		http.get.mockResolvedValueOnce({ data: { settings: { b: 2 } } })
		expect(await customers.getCustomerSettings()).toEqual({ b: 2 })
	})

	it('reads whether billing can take over, as plain flags', async () => {
		http.get.mockResolvedValueOnce({ data: { available: 1, isManager: 0 } })
		expect(await billing.getBillingHandoffAvailability('c1')).toEqual({ available: true, deepLinkUrl: '', isManager: false })
		expect(http.get).toHaveBeenLastCalledWith(`${P}/api/billing/handoff/c1/availability`)
		await billing.sendToBilling('c1', '2026-01-01', '2026-03-31')
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/billing/handoff/c1`, { periodStart: '2026-01-01', periodEnd: '2026-03-31' })
	})

	it('raises the journal entry for a Z report', async () => {
		expect(await raiseJournalEntry('z1')).toBeNull()
		http.post.mockResolvedValueOnce({ data: { zReport: { id: 'z1', posted: true } } })
		expect(await raiseJournalEntry('z1')).toEqual({ id: 'z1', posted: true })
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/pos-bookkeeping/post`, { zReportId: 'z1' })
	})
})
