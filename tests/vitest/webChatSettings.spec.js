/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The admin Web chat section's helpers (requests-web-chat-and-bot task 1.2).
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-an-administrator-puts-a-chat-on-the-website-req-wch-001
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateFilePath: (app, type, file) => '/custom_apps/' + app + '/' + type + '/' + file,
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, key) => params[key]),
}))

const axios = (await import('@nextcloud/axios')).default
const { embedLine, newChannelToken, parseOrigins, saveChannel } = await import('../../src/services/webChatChannels.js')

describe('the admin web chat section', () => {
	it('renders the embed line with the channel token', () => {
		expect(embedLine('https://cloud.example.org', '/custom_apps/pipelinq/js/pipelinq-web-chat.js', '/index.php/apps/pipelinq/chat/abcdefghijklmnop1234')).toBe(
			'<script src="https://cloud.example.org/custom_apps/pipelinq/js/pipelinq-web-chat.js?chat='
			+ encodeURIComponent('https://cloud.example.org/index.php/apps/pipelinq/chat/abcdefghijklmnop1234')
			+ '" async></script>',
		)
	})

	it('makes a 32-character token of letters and digits', () => {
		const token = newChannelToken({ getRandomValues: (bytes) => bytes.fill(61) })
		expect(token).toMatch(/^[A-Za-z0-9]{32}$/)
		expect(token).toBe('9'.repeat(32))
		expect(newChannelToken()).toMatch(/^[A-Za-z0-9]{32}$/)
	})

	it('keeps exact http(s) origins only', () => {
		expect(parseOrigins('https://www.gemeente.nl/\nHTTPS://www.gemeente.nl, https://wijk.gemeente.nl:8443 javascript:alert(1) https://evil.example/path')).toEqual(
			['https://www.gemeente.nl', 'https://wijk.gemeente.nl:8443'],
		)
	})

	it('adds a new channel switched off, with a fresh token, and updates an existing one in place', async () => {
		axios.post.mockResolvedValue({ data: { id: 'n' } })
		axios.put.mockResolvedValue({ data: { id: 'c-1' } })

		await saveChannel({ name: 'Website', active: true, channelToken: 'chosen' })
		await saveChannel({ id: 'c-1', '@self': {}, name: 'Website', active: true, channelToken: 'kept' })

		const created = axios.post.mock.calls[0][1]
		expect(axios.post.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/pipelinq/webChatChannel')
		expect(created.active).toBe(false)
		expect(created.channelToken).toMatch(/^[A-Za-z0-9]{32}$/)
		expect(created.channelToken).not.toBe('chosen')
		expect(axios.put.mock.calls[0]).toEqual(['/index.php/apps/openregister/api/objects/pipelinq/webChatChannel/c-1', { name: 'Website', active: true, channelToken: 'kept' }])
	})
})
