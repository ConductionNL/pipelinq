// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recent activities widget: how long ago each change happened, and where
 * a row opens.
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

const openRowTarget = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, openRowTarget }))
vi.mock('../../src/services/localeUtils.js', () => ({ formatDate: (v) => `d:${v}` }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))

let methods

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, k) => vars[k])
	methods = (await import('../../src/views/widgets/RecentActivitiesWidget.vue')).default.methods
}, 60000)

afterEach(() => {
	vi.useRealTimers()
	openRowTarget.mockClear()
})

describe('RecentActivitiesWidget.formatTimeAgo', () => {
	it('humanises the time since a change by its largest unit under a week', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-13T12:00:00Z'))

		expect(methods.formatTimeAgo('')).toBe('')
		expect(methods.formatTimeAgo('2026-11-13T11:59:30Z')).toBe('just now')
		expect(methods.formatTimeAgo('2026-11-13T11:15:00Z')).toBe('45m ago')
		expect(methods.formatTimeAgo('2026-11-13T09:00:00Z')).toBe('3h ago')
		expect(methods.formatTimeAgo('2026-11-11T12:00:00Z')).toBe('2d ago')
		expect(methods.formatTimeAgo('2026-11-01T12:00:00Z')).toBe('d:2026-11-01T12:00:00Z')
	})

	it('hands an unreadable date to the date formatter unchanged', () => {
		expect(methods.formatTimeAgo('not a date')).toBe('d:not a date')
	})
})

describe('RecentActivitiesWidget.onShow', () => {
	it('opens a lead on the leads route and anything else on the tickets route', () => {
		const event = { type: 'click' }

		methods.onShow({ _entityType: 'lead', _entityId: 'l1' }, event)
		methods.onShow({ _entityType: 'request', _entityId: 't1' }, event)

		expect(openRowTarget.mock.calls).toEqual([
			[event, '/index.php/apps/pipelinq/leads/l1'],
			[event, '/index.php/apps/pipelinq/tickets/t1'],
		])
	})
})
