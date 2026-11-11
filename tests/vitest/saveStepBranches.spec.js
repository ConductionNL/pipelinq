// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of two saves pinned before they were split into named steps:
 * logging a visit with its follow-up task, and converting a question into
 * a Woo request.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const store = vi.hoisted(() => ({ saveObject: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (match, key) => vars[key]) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {} }))
vi.mock('../../src/dialogs/LogVisitDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

let logVisit
let woo

beforeAll(async () => {
	globalThis.t = (app, text) => text
	logVisit = (await import('../../src/components/LogVisitAction.vue')).default
	woo = (await import('../../src/components/WooConversionSection.vue')).default
}, 60000)

beforeEach(() => {
	Object.values(dialogs).forEach((fn) => fn.mockReset())
	store.saveObject.mockReset()
	http.post.mockReset()
	window.OC = { getCurrentUser: () => ({ uid: 'u1' }) }
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-03-01T10:00:00Z'))
})

afterEach(() => {
	vi.useRealTimers()
	delete window.OC
})

describe('LogVisitAction.save', () => {
	/**
	 * @param {object} state The action's state.
	 * @return {object} An action context.
	 */
	function ctx(state) {
		return { ...logVisit.methods, visitClientId: 'c1', visitLeadId: '', saving: false, error: 'old', open: true, $emit: vi.fn(), ...state }
	}

	it('logs the visit for the client and the user', async () => {
		store.saveObject.mockResolvedValue({ id: 't1' })
		const c = ctx({})
		const pending = logVisit.methods.save.call(c, { note: ' Fine ' })
		expect(c.saving).toBe(true)
		expect(c.error).toBe('')
		await pending
		expect(store.saveObject).toHaveBeenCalledTimes(1)
		const [type, ticket] = store.saveObject.mock.calls[0]
		expect(type).toBe('ticket')
		expect(ticket).toMatchObject({ title: 'Visit', description: 'Fine', client: 'c1', assignee: 'u1' })
		expect(ticket.lead).toBeUndefined()
		expect(c.open).toBe(false)
		expect(c.$emit).toHaveBeenCalledWith('saved', { id: 't1' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Visit logged')
		expect(c.saving).toBe(false)
	})

	it('also stores the follow-up task, linked to the visit', async () => {
		store.saveObject.mockResolvedValueOnce({ id: 't1' }).mockResolvedValueOnce({ id: 'task1' })
		const c = ctx({ visitLeadId: 'l1' })
		await logVisit.methods.save.call(c, { note: 'n', followUpDate: '2026-03-08' })
		expect(store.saveObject).toHaveBeenCalledTimes(2)
		expect(store.saveObject.mock.calls[1][0]).toBe('crmTask')
		expect(store.saveObject.mock.calls[1][1]).toMatchObject({ subject: 'Follow up on the visit', lead: 'l1', clientId: 'c1' })
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Visit logged')
	})

	it('keeps the visit when only the task fails, and says so', async () => {
		store.saveObject.mockResolvedValueOnce({ id: 't1' }).mockResolvedValueOnce(null)
		const c = ctx({})
		await logVisit.methods.save.call(c, { note: 'n', followUpDate: '2026-03-08' })
		expect(dialogs.showError).toHaveBeenCalledWith('The visit is saved, the follow-up task is not. Add it from the task list.')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(c.open).toBe(false)
		expect(c.error).toBe('')
		expect(c.saving).toBe(false)
		store.saveObject.mockResolvedValueOnce({ id: 't2' }).mockRejectedValueOnce(new Error('x'))
		await logVisit.methods.save.call(ctx({}), { note: 'n', followUpDate: '2026-03-08' })
		expect(dialogs.showError).toHaveBeenCalledTimes(2)
	})

	it('keeps the dialog open with the failure when the visit is not saved', async () => {
		store.saveObject.mockResolvedValue(null)
		const c = ctx({})
		await logVisit.methods.save.call(c, { note: 'n' })
		expect(c.error).toBe('The visit could not be saved.')
		expect(c.open).toBe(true)
		expect(c.$emit).not.toHaveBeenCalled()
		store.saveObject.mockRejectedValue(new Error('Server says no'))
		await logVisit.methods.save.call(c, { note: 'n' })
		expect(c.error).toBe('Server says no')
		store.saveObject.mockRejectedValue({})
		await logVisit.methods.save.call(c, { note: 'n' })
		expect(c.error).toBe('The visit could not be saved.')
		expect(c.saving).toBe(false)
	})
})

describe('WooConversionSection.convert', () => {
	/**
	 * @param {object} state The section's state.
	 * @return {object} A section context.
	 */
	function ctx(state) {
		return {
			...woo.methods,
			busy: false,
			error: 'old',
			objectId: 't1',
			status: 'open',
			caseReference: null,
			caseUrl: null,
			canConvert: true,
			loadAvailability: vi.fn(async () => {}),
			...state,
		}
	}

	it('converts the question and keeps only a safe case link', async () => {
		http.post.mockResolvedValue({ data: { caseReference: 'W-1', caseUrl: '/apps/dossiq/cases/1' } })
		const c = ctx({})
		const pending = woo.methods.convert.call(c)
		expect(c.busy).toBe(true)
		expect(c.error).toBe('')
		await pending
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/tickets/t1/woo-request', {})
		expect(c).toMatchObject({ status: 'converted', caseReference: 'W-1', caseUrl: '/apps/dossiq/cases/1', canConvert: false, busy: false })
		for (const [caseUrl, kept] of [['https://d.nl/c', 'https://d.nl/c'], ['javascript:alert(1)', ''], [undefined, ''], [7, '']]) {
			http.post.mockResolvedValue({ data: { caseUrl } })
			const d = ctx({})
			await woo.methods.convert.call(d)
			expect(d.caseUrl).toBe(kept)
			expect(d.caseReference).toBe('')
		}
	})

	it('does nothing while busy, and says why a conversion failed', async () => {
		const busy = ctx({ busy: true })
		await woo.methods.convert.call(busy)
		expect(http.post).not.toHaveBeenCalled()
		expect(busy.error).toBe('old')
		http.post.mockRejectedValue({ response: { data: { status: 'intake-failed' } } })
		const c = ctx({})
		await woo.methods.convert.call(c)
		expect(c.error).toBe('dossiq could not start the Woo request. Try again later.')
		expect(c.loadAvailability).toHaveBeenCalledTimes(1)
		expect(c.status).toBe('open')
		for (const failure of [{ response: { data: { status: 'gone' } } }, null, new Error('x')]) {
			http.post.mockRejectedValue(failure)
			const d = ctx({})
			await woo.methods.convert.call(d)
			expect(d.error).toBe('This question can no longer be converted.')
			expect(d.busy).toBe(false)
		}
	})
})
