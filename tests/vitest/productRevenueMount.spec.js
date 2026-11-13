// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The product revenue card as mounted: it starts empty, reads the line items
 * through the shared object store's registry on mount, and prints each top
 * product's total in the user's currency format.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { formatCurrency } from '../../src/services/localeUtils.js'

const store = vi.hoisted(() => ({ value: null }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: { template: '<span class="loading" />' } }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store.value }))

globalThis.t = (_app, text) => text
globalThis.OC = { requestToken: 'tok' }

const { default: ProductRevenue } = await import('../../src/components/ProductRevenue.vue')

const registry = { leadProduct: { register: 'r', schema: 'lp' }, product: { register: 'r', schema: 'p' } }

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('ProductRevenue mounted', () => {
	it('starts empty and not loading', () => {
		expect(ProductRevenue.data()).toEqual({ loading: false, topProducts: [] })
	})

	it('reads the line items on mount through the store registry and shows the top products', async () => {
		const fetchObject = vi.fn(async (_type, id) => ({ name: id === 'p1' ? 'Hosting' : 'Support' }))
		store.value = { objectTypeRegistry: registry, fetchObject }
		const fetch = vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ product: 'p1', total: 1200, lead: 'l1' }, { product: 'p2', total: 300, lead: 'l2' }] }) }))
		vi.stubGlobal('fetch', fetch)

		const wrapper = mount(ProductRevenue, { global: { mocks: { t: (_app, text) => text } } })
		await flushPromises()

		expect(fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/r/lp?_limit=500', expect.any(Object))
		expect(wrapper.vm.objectStore).toBe(store.value)
		expect(wrapper.vm.topProducts.map((p) => p.name)).toEqual(['Hosting', 'Support'])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('prints an amount in the locale currency format', () => {
		expect(ProductRevenue.methods.formatCurrency(1234.5)).toBe(formatCurrency(1234.5))
		expect(ProductRevenue.methods.formatCurrency(1234.5)).not.toBe('')
	})
})
