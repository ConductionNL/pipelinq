/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of two loaders pinned before they were split into named steps:
 * the website submission's ticket and suggested client and contact, and
 * finishing a social account connection.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const social = vi.hoisted(() => ({
	attachCredential: vi.fn(),
	fetchAccounts: vi.fn(),
	fetchConnectParameters: vi.fn(),
	revokeAccount: vi.fn(),
	startBrokerConnection: vi.fn(),
}))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {}, CnPartyPicker: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))
vi.mock('../../src/services/socialApi.js', () => social)

let linkModal
let accounts

beforeAll(async () => {
	globalThis.t = (app, text) => text
	linkModal = (await import('../../src/modals/LinkSubmissionToClientModal.vue')).default
	accounts = (await import('../../src/views/social/SocialAccountsView.vue')).default
}, 60000)

beforeEach(() => {
	http.get.mockReset()
	Object.values(social).forEach((fn) => fn.mockReset())
})

describe('LinkSubmissionToClientModal.load', () => {
	/**
	 * @return {object} A modal context whose party reads the loaded ticket.
	 */
	function ctx() {
		const c = {
			...linkModal.methods,
			ticketId: 't 1',
			ticket: 'old',
			loading: true,
			suggestedClient: null,
			suggestedContact: null,
			clientMode: 'new',
			contactMode: 'new',
			clientId: '',
			contactId: '',
		}
		Object.defineProperty(c, 'party', { get: () => linkModal.computed.party.call(c) })
		return c
	}

	/**
	 * @param {object} rows The rows per schema answered.
	 */
	function answer(rows) {
		http.get.mockImplementation(async (url, options) => {
			if (url.includes('/ticket/')) {
				return { data: rows.ticket }
			}
			const schema = url.split('/').pop()
			if (rows[schema] instanceof Error) {
				throw rows[schema]
			}
			return { data: { results: rows[schema] || [], params: options.params } }
		})
	}

	it('suggests the client and contact the submission names', async () => {
		answer({
			ticket: { reporterOrganisation: 'Acme', reporterEmail: 'A@B.nl' },
			client: [{ id: 7, name: 'Acme BV' }],
			contact: [{ '@self': { id: 'k1' }, name: 'Anna' }],
		})
		const c = ctx()
		await linkModal.methods.load.call(c)
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/ticket/t%201')
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/client', { params: { name: 'Acme', _limit: 1 } })
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/contact', { params: { email: 'a@b.nl', _limit: 1 } })
		expect(c).toMatchObject({
			suggestedClient: { id: '7', name: 'Acme BV' },
			clientMode: 'existing',
			clientId: '7',
			suggestedContact: { id: 'k1', name: 'Anna' },
			contactMode: 'existing',
			contactId: 'k1',
			loading: false,
		})
	})

	it('suggests nothing it cannot find or the submission does not name', async () => {
		answer({ ticket: { reporterEmail: 'x@y.nl' }, contact: new Error('down') })
		const c = ctx()
		await linkModal.methods.load.call(c)
		expect(http.get).toHaveBeenCalledTimes(2)
		expect(c).toMatchObject({ suggestedClient: null, suggestedContact: null, clientMode: 'new', contactMode: 'new', loading: false })
	})

	it('stops without the ticket', async () => {
		http.get.mockRejectedValue(new Error('gone'))
		const c = ctx()
		await linkModal.methods.load.call(c)
		expect(c.ticket).toBeNull()
		expect(http.get).toHaveBeenCalledTimes(1)
		expect(c.loading).toBe(false)
		answer({ ticket: null })
		const empty = ctx()
		await linkModal.methods.load.call(empty)
		expect(empty.ticket).toBeNull()
		expect(http.get).toHaveBeenCalledTimes(2)
	})
})

describe('SocialAccountsView.finishConnection', () => {
	/**
	 * @param {object} $route The route back from the broker.
	 * @return {object} A view context.
	 */
	function ctx($route) {
		return { ...accounts.methods, $route, error: '', notice: '', accounts: [], readiness: null }
	}

	it('records a completed connection and reloads the accounts', async () => {
		social.fetchAccounts.mockResolvedValue({ data: [{ id: 'a1' }], readiness: { ok: true } })
		const c = ctx({ params: { id: 'a1' }, query: { connected: 'ok' } })
		await accounts.methods.finishConnection.call(c)
		expect(social.attachCredential).toHaveBeenCalledWith('a1')
		expect(c).toMatchObject({ notice: 'The account is connected.', accounts: [{ id: 'a1' }], readiness: { ok: true }, error: '' })
	})

	it('does nothing without an account or an outcome, and says why it did not connect', async () => {
		for (const $route of [undefined, { params: {}, query: { connected: 'ok' } }, { params: { id: 'a1' }, query: {} }]) {
			const c = ctx($route)
			await accounts.methods.finishConnection.call(c)
			expect(c.error).toBe('')
		}
		expect(social.attachCredential).not.toHaveBeenCalled()
		const refused = ctx({ params: { id: 'a1' }, query: { connected: 'denied' } })
		await accounts.methods.finishConnection.call(refused)
		expect(refused.error).toBe('The connection was not completed. Try connecting the account again.')
		expect(social.attachCredential).not.toHaveBeenCalled()
		social.attachCredential.mockRejectedValue({ response: { data: { error: 'Expired' } } })
		const failing = ctx({ params: { id: 'a1' }, query: { connected: 'ok' } })
		await accounts.methods.finishConnection.call(failing)
		expect(failing.error).toBe('Expired')
		expect(failing.notice).toBe('')
		social.attachCredential.mockResolvedValue()
		social.fetchAccounts.mockRejectedValue(new Error('x'))
		const reloadFails = ctx({ params: { id: 'a1' }, query: { connected: 'ok' } })
		await accounts.methods.finishConnection.call(reloadFails)
		expect(reloadFails.notice).toBe('The account is connected.')
		expect(reloadFails.error).toBe('The connection could not be recorded.')
	})
})
