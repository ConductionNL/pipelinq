// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The contact-first create, the write-back and the address book status calls.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-a-failed-write-back-is-shown-to-the-user
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { cardStatus, createWithContact, writeBack } = await import('../../src/services/contactSyncApi.js')

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('createWithContact', () => {
	it('posts the type and the raw form and returns the created object', async () => {
		axios.post.mockResolvedValueOnce({ data: { object: { id: 'c1' } } })
		expect(await createWithContact('client', { name: 'Acme' })).toEqual({ id: 'c1' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contacts-sync/create', {
			objectType: 'client',
			object: { name: 'Acme' },
		})
	})

	it('lets a refusal reach the caller', async () => {
		const failure = new Error('Contacts disabled')
		axios.post.mockRejectedValueOnce(failure)
		await expect(createWithContact('contact', {})).rejects.toBe(failure)
	})
})

describe('writeBack', () => {
	it('returns the contacts UID and stays quiet on success', async () => {
		const notify = vi.fn()
		axios.post.mockResolvedValueOnce({ data: { contactsUid: 'uid-1' } })
		expect(await writeBack('client', 'o1', notify)).toBe('uid-1')
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contacts-sync/write-back', {
			objectType: 'client',
			objectId: 'o1',
		})
		expect(notify).not.toHaveBeenCalled()
	})

	it('stays quiet when the user turned write-back off', async () => {
		const notify = vi.fn()
		axios.post.mockResolvedValueOnce({ data: { skipped: true } })
		expect(await writeBack('client', 'o1', notify)).toBeNull()
		expect(notify).not.toHaveBeenCalled()
	})

	it('tells the user when the write-back failed or returned no UID', async () => {
		const notify = vi.fn()
		axios.post.mockRejectedValueOnce(new Error('500'))
		expect(await writeBack('contact', 'o2', notify)).toBeNull()
		axios.post.mockResolvedValueOnce({ data: {} })
		expect(await writeBack('contact', 'o2', notify)).toBeNull()
		expect(notify).toHaveBeenCalledTimes(2)
		expect(notify).toHaveBeenCalledWith('Your changes are saved here, but not in Nextcloud Contacts. The contact there still has the old details.')
	})
})

describe('cardStatus', () => {
	it('asks the status for the UID and returns the answer', async () => {
		axios.get.mockResolvedValueOnce({ data: { linked: true, addressBook: 'Work', updatedAt: null } })
		expect(await cardStatus('uid-1')).toEqual({ linked: true, addressBook: 'Work', updatedAt: null })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contacts-sync/status', { params: { uid: 'uid-1' } })
	})
})
