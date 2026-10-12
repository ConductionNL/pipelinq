/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * pipelinq asks buildiq for persisted manifest overrides only when buildiq is
 * there to answer. Without buildiq the GET came back 404 on every page load.
 *
 * useAppStatus and mergeManifestDelta are the library's real modules, loaded
 * from their definition files (the package root pulls the whole component set,
 * which vitest cannot load). Only the HTTP client is a fake, so the test can
 * count requests. Each test resets the module cache, because useAppStatus
 * caches its answer for the page lifetime.
 *
 * @spec openspec/changes/round6-nextcloud-vue-2-78/specs/declarative-view-system/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()

vi.mock('@nextcloud/axios', () => ({ default: { get } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/capabilities', () => ({ getCapabilities: () => ({}) }))
const OVERRIDE_URL = '/apps/buildiq/api/app-overrides/pipelinq'

/**
 * A small build-time manifest with one page whose title an override changes.
 *
 * @return {object}
 */
function manifest() {
	return {
		version: '1.0.0',
		pages: [
			{ id: 'clients', route: '/clients', type: 'index', title: 'Clients' },
		],
	}
}

/**
 * Load the service fresh, with the given apps enabled for the user.
 *
 * @param {string[]} apps App ids in OC.appswebroots.
 * @return {Promise<Function>} loadPersistedOverrides
 */
async function loadWith(apps) {
	globalThis.OC = {
		appswebroots: Object.fromEntries(apps.map((id) => [id, `/apps/${id}`])),
	}
	vi.resetModules()
	// doMock, not mock: a hoisted vi.mock factory runs once per file, so the
	// library module (and useAppStatus's per-app cache) would outlive the reset.
	vi.doMock('@conduction/nextcloud-vue', async () => {
		const { useAppStatus } =
			await import('@conduction/nextcloud-vue/src/composables/useAppStatus.js')
		const { mergeManifestDelta } =
			await import('@conduction/nextcloud-vue/src/utils/mergeManifestDelta.js')
		return { useAppStatus, mergeManifestDelta }
	})
	const mod = await import('../../src/services/persistedOverrides.js')
	return mod.loadPersistedOverrides
}

beforeEach(() => {
	get.mockReset()
})

afterEach(() => {
	delete globalThis.OC
})

describe('loadPersistedOverrides', () => {
	it('makes no request when buildiq is not installed', async () => {
		const loadPersistedOverrides = await loadWith(['pipelinq', 'openregister'])
		get.mockRejectedValue({ response: { status: 404 } })

		const base = manifest()
		const result = await loadPersistedOverrides(base)

		expect(get).not.toHaveBeenCalled()
		expect(result).toBe(base)
	})

	it('asks buildiq and applies the delta when buildiq is installed', async () => {
		const loadPersistedOverrides = await loadWith(['pipelinq', 'buildiq'])
		get.mockResolvedValue({
			data: { pages: [{ id: 'clients', title: 'Customers' }] },
		})

		const result = await loadPersistedOverrides(manifest())

		expect(get).toHaveBeenCalledTimes(1)
		expect(get.mock.calls[0][0]).toBe(OVERRIDE_URL)
		expect(result.pages[0].title).toBe('Customers')
		expect(result.pages[0].route).toBe('/clients')
	})

	it('asks under the old app id openbuild too', async () => {
		const loadPersistedOverrides = await loadWith(['pipelinq', 'openbuild'])
		get.mockResolvedValue({ data: {} })

		const base = manifest()
		const result = await loadPersistedOverrides(base)

		expect(get).toHaveBeenCalledTimes(1)
		expect(result).toBe(base)
	})
})
