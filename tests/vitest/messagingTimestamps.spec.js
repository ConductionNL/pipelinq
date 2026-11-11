// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the messaging settings and a conversation show a stored moment: a dash
 * when there is none, the stored text when it is not a date, else the date
 * and time in the browser's locale.
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: {}, CnStatusBadge: {}, CnDialog: {} }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p, imagePath: (app, p) => p }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

const formatters = {}

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	formatters.settings = (await import('../../src/views/settings/MessagingSettings.vue')).default.methods.formatDate
	formatters.conversation = (await import('../../src/views/messaging/MessagingConversationSection.vue')).default.methods.formatDate
}, 60000)

describe.each(['settings', 'conversation'])('the %s', (name) => {
	it('shows a dash, the stored text, or the local date and time', () => {
		const at = '2026-11-11T09:30:00Z'
		expect(formatters[name]('')).toBe('—')
		expect(formatters[name](null)).toBe('—')
		expect(formatters[name](undefined)).toBe('—')
		expect(formatters[name]('soon')).toBe('soon')
		expect(formatters[name](at)).toBe(new Date(at).toLocaleString())
	})
})
