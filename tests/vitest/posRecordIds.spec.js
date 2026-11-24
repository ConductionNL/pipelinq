/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the tender screens read the id of a tender or a tender type: the
 * OpenRegister `@self.id` first, then `id`, then `uuid`, else none.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/specs.md#req-pst-002-add-tender-to-transaction-mvp
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnDialog: {} }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: {} }))

const readers = {}

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	readers.entryType = (await import('../../src/components/pos/TenderEntryPanel.vue')).default.methods.idOf
	readers.entryTender = (await import('../../src/components/pos/TenderEntryPanel.vue')).default.methods.tenderId
	readers.form = (await import('../../src/modals/PosTenderTypeFormDialog.vue')).default.methods.idOf
	readers.add = (await import('../../src/modals/AddTenderDialog.vue')).default.methods.idOf
	readers.list = (await import('../../src/views/pos/PosTenderTypeList.vue')).default.methods.idOf
}, 60000)

const records = [
	[{ '@self': { id: 's1' }, id: 'i1', uuid: 'u1' }, 's1'],
	[{ '@self': {}, id: 'i2', uuid: 'u2' }, 'i2'],
	[{ '@self': { id: '' }, uuid: 'u3' }, 'u3'],
	[{ id: 0, uuid: '' }, ''],
	[{}, ''],
	[null, ''],
	[undefined, ''],
]

describe.each(['entryType', 'entryTender', 'form', 'add', 'list'])('the %s reader', (name) => {
	it('takes @self.id, then id, then uuid, else nothing', () => {
		for (const [record, id] of records) {
			expect(readers[name](record)).toBe(id)
		}
	})
})
