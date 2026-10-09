/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A failed write-back to Nextcloud Contacts is shown to the user (round 4,
 * item 1). The cloud check saw POST /api/contacts-sync/write-back answer 500
 * while the client page said nothing, so the user believed the contact had
 * the new phone number.
 *
 * @spec openspec/changes/round4-contact-write-back/specs/contacts-sync/spec.md#requirement-a-failed-write-back-is-shown-to-the-user
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: { post: (...args) => post(...args) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const { writeBack } = await import('../../src/services/contactSyncApi.js')

describe('contact write-back failure', () => {
	beforeEach(() => {
		post.mockReset()
	})

	it('tells the user when the server refuses the write-back', async () => {
		post.mockRejectedValue(
			Object.assign(new Error('500'), {
				response: { status: 500, data: { success: false } },
			}),
		)
		const notify = vi.fn()

		const uid = await writeBack('client', 'c-1', notify)

		expect(uid).toBeNull()
		expect(notify).toHaveBeenCalledTimes(1)
		expect(notify.mock.calls[0][0]).toContain('not in Nextcloud Contacts')
	})

	it('tells the user when the answer carries no contact', async () => {
		post.mockResolvedValue({ data: { success: false } })
		const notify = vi.fn()

		expect(await writeBack('client', 'c-1', notify)).toBeNull()
		expect(notify).toHaveBeenCalledTimes(1)
	})

	it('stays quiet when the contact was updated', async () => {
		post.mockResolvedValue({ data: { success: true, contactsUid: 'uid-1' } })
		const notify = vi.fn()

		expect(await writeBack('client', 'c-1', notify)).toBe('uid-1')
		expect(notify).not.toHaveBeenCalled()
		expect(post).toHaveBeenCalledWith(
			'/apps/pipelinq/api/contacts-sync/write-back',
			{
				objectType: 'client',
				objectId: 'c-1',
			},
		)
	})
})
