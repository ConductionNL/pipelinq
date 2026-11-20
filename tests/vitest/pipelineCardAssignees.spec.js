// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline card's assignee list comes from core autocomplete, which every
 * signed-in user may call, through the instance's OCS URL. It is fetched once
 * for the whole board and only when someone opens the Assign dialog: the
 * provisioning API the cards used to call on mount is admin-only, fired once
 * per card, and ignored sub-folder installs.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (p) => `/sub/ocs/v2.php/${p}`,
}))

const { listAssignableUserIds, resetAssignableUsers } =
	await import('../../src/services/userDisplayName.js')

beforeEach(() => {
	axiosMock.get.mockReset()
	resetAssignableUsers()
})

describe('listAssignableUserIds', () => {
	it('reads the uids from core autocomplete through generateOcsUrl', async () => {
		axiosMock.get.mockResolvedValue({
			data: { ocs: { data: [{ id: 'anna', label: 'Anna' }, { id: 'bert', label: 'Bert' }] } },
		})
		await expect(listAssignableUserIds()).resolves.toEqual(['anna', 'bert'])
		const [url, config] = axiosMock.get.mock.calls[0]
		expect(url).toBe('/sub/ocs/v2.php/core/autocomplete/get')
		expect(config.params['shareTypes[]']).toBe(0)
	})

	it('asks the server once, however many cards ask', async () => {
		axiosMock.get.mockResolvedValue({ data: { ocs: { data: [{ id: 'anna' }] } } })
		await Promise.all([listAssignableUserIds(), listAssignableUserIds(), listAssignableUserIds()])
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
	})

	it('answers an empty list on a failure and asks again next time', async () => {
		axiosMock.get.mockRejectedValueOnce(new Error('boom'))
		await expect(listAssignableUserIds()).resolves.toEqual([])
		axiosMock.get.mockResolvedValueOnce({ data: { ocs: { data: [{ id: 'anna' }] } } })
		await expect(listAssignableUserIds()).resolves.toEqual(['anna'])
	})
})

describe('PipelineCard', () => {
	it('does not fetch users when it mounts', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/pipeline/PipelineCard.vue'), 'utf8')
		expect(source).not.toMatch(/cloud\/users/)
		expect(source).not.toMatch(/mounted\(\)\s*\{[^}]*loadUsers/)
	})
})
