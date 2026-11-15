/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BI export endpoints the export pages call: their method and path,
 * no body, the parsed answer, and the error a refusal throws.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))

let exportApi

beforeAll(async () => {
	globalThis.OC = { requestToken: 'tok' }
	exportApi = (await import('../../src/services/exportApi.js')).exportApi
})

afterEach(() => {
	vi.unstubAllGlobals()
})

function answer(ok, body) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok, json: body instanceof Error ? async () => { throw body } : async () => body })))
}

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }

describe('exportApi', () => {
	it('asks each endpoint with its method and no body', async () => {
		answer(true, { ok: 1 })
		const calls = [
			[() => exportApi.testDestination('d1'), 'POST', '/destinations/d1/test'],
			[() => exportApi.testRun('j1'), 'POST', '/jobs/j1/test-run'],
			[() => exportApi.enableJob('j1'), 'POST', '/jobs/j1/enable'],
			[() => exportApi.disableJob('j1'), 'POST', '/jobs/j1/disable'],
			[() => exportApi.getRun('r1'), 'GET', '/runs/r1'],
			[() => exportApi.retryRun('r1'), 'POST', '/runs/r1/retry'],
		]
		for (const [call, method, path] of calls) {
			await call()
			expect(fetch).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/export' + path, { method, headers })
		}
	})

	it('throws the server error of a refusal, or a general one', async () => {
		answer(false, { error: 'Unreachable' })
		await expect(exportApi.testDestination('d1')).rejects.toThrow('Unreachable')
		answer(false, new Error('no json'))
		await expect(exportApi.getRun('r1')).rejects.toThrow('Export request failed')
		answer(true, new Error('no json'))
		expect(await exportApi.getRun('r1')).toEqual({})
	})
})
