/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings store's getters: the plain state reads, the per-category
 * complaint SLA and the stale-lead threshold with its fallbacks.
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { useSettingsStore } = await import('../../src/store/modules/settings.js')

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('settings store plain getters', () => {
	it('mirror the state', () => {
		const store = useSettingsStore()
		expect([store.isLoading, store.getError, store.isInitialized, store.getConfig, store.hasOpenRegisters, store.getIsAdmin])
			.toEqual([false, null, false, null, false, false])
		store.$patch({ loading: true, error: 'boom', initialized: true, config: { a: 1 }, openRegisters: true, isAdmin: true })
		expect([store.isLoading, store.getError, store.isInitialized, store.getConfig, store.hasOpenRegisters, store.getIsAdmin])
			.toEqual([true, 'boom', true, { a: 1 }, true, true])
	})
})

describe('getComplaintSlaHours', () => {
	it('is 0 before the settings are loaded', () => {
		expect(useSettingsStore().getComplaintSlaHours('service')).toBe(0)
	})

	it('reads complaint_sla_<category> as whole hours', () => {
		const store = useSettingsStore()
		store.config = { complaint_sla_service: '48', complaint_sla_billing: 12, complaint_sla_delivery: '7.9h' }
		expect(store.getComplaintSlaHours('service')).toBe(48)
		expect(store.getComplaintSlaHours('billing')).toBe(12)
		expect(store.getComplaintSlaHours('delivery')).toBe(7)
	})

	it('is 0 for a missing or non-numeric category value', () => {
		const store = useSettingsStore()
		store.config = { complaint_sla_service: 'soon' }
		expect(store.getComplaintSlaHours('service')).toBe(0)
		expect(store.getComplaintSlaHours('other')).toBe(0)
	})
})

describe('getLeadStaleThresholdDays', () => {
	it('defaults to 14 days before the settings are loaded', () => {
		expect(useSettingsStore().getLeadStaleThresholdDays).toBe(14)
	})

	it('reads a positive whole number of days', () => {
		const store = useSettingsStore()
		store.config = { lead_stale_threshold_days: '30' }
		expect(store.getLeadStaleThresholdDays).toBe(30)
		store.config = { lead_stale_threshold_days: 1 }
		expect(store.getLeadStaleThresholdDays).toBe(1)
	})

	it.each([['0'], ['-3'], ['never'], [undefined], [null]])('falls back to 14 for %s', (value) => {
		const store = useSettingsStore()
		store.config = { lead_stale_threshold_days: value }
		expect(store.getLeadStaleThresholdDays).toBe(14)
	})
})
