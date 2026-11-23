// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The POS staff list and form read the till roles from one endpoint: the
 * roles it answers, none when it answers without them, none when it fails.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
 */
import { describe, expect, it, vi } from 'vitest'

const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet, post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: {} }))

globalThis.t = (_app, text) => text

const views = {
	list: (await import('../../src/views/pos/PosStaffList.vue')).default,
	form: (await import('../../src/views/pos/PosStaffForm.vue')).default,
}

describe.each(Object.keys(views))('the staff %s', (name) => {
	const loadRoles = async () => {
		const ctx = { roles: ['stale'] }
		await views[name].methods.loadRoles.call(ctx)
		return ctx.roles
	}

	it('reads the roles the endpoint answers', async () => {
		axiosGet.mockResolvedValueOnce({ data: { roles: [{ id: 'r1' }] } })
		expect(await loadRoles()).toEqual([{ id: 'r1' }])
		expect(axiosGet).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/pos/roles')
	})

	it('reads none when the answer has no roles, or the request fails', async () => {
		axiosGet.mockResolvedValueOnce({ data: {} })
		expect(await loadRoles()).toEqual([])
		axiosGet.mockResolvedValueOnce(undefined)
		expect(await loadRoles()).toEqual([])
		axiosGet.mockRejectedValueOnce(new Error('down'))
		expect(await loadRoles()).toEqual([])
	})
})
