/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The hand-off sections of a client, a contract and a request read their
 * object id from their own prop, else from the section context the page host
 * provides (a ref or a plain object), else none.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('../../src/dialogs/ConvertToCaseDialog.vue', () => ({ default: {} }))
vi.mock('../../src/services/billingHandoffApi.js', () => ({ getBillingHandoffAvailability: vi.fn(), sendToBilling: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const sections = {}

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	sections.billing = (await import('../../src/components/billing/ClientBillingHandoffSection.vue')).default
	sections.contract = (await import('../../src/views/contracts/ContractInvoicingSection.vue')).default
	sections.request = (await import('../../src/views/requests/RequestConversionSection.vue')).default
}, 60000)

const hosts = [
	[null, ''],
	[{ value: { objectId: 'o1' } }, 'o1'],
	[{ value: { id: 'x' } }, ''],
	[{ value: null }, ''],
	[{ objectId: 'o2' }, 'o2'],
	['text', ''],
]

describe.each([
	['billing', 'clientId'],
	['contract', 'contractId'],
	['request', 'requestId'],
])('the %s section', (name, prop) => {
	const resolvedId = (state) => sections[name].computed.resolvedId.call(state)

	it('reads the id from the host when its prop is empty', () => {
		for (const [cnSectionContext, id] of hosts) {
			expect(resolvedId({ [prop]: '', cnSectionContext })).toBe(id)
		}
	})

	it('prefers its own prop', () => {
		expect(resolvedId({ [prop]: 'p1', cnSectionContext: { objectId: 'o' } })).toBe('p1')
		expect(resolvedId({ [prop]: 'p2', cnSectionContext: null })).toBe('p2')
	})
})
