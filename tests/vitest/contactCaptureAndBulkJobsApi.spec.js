/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact capture client and the bulk job client: which endpoint each
 * call reaches, what it sends, and what a failure answers.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const axiosGet = vi.hoisted(() => vi.fn())
const axiosPost = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet, post: axiosPost } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { captureStatus, parseText, parsePhoto } = await import('../../src/services/contactCaptureApi.js')
const { bulkJobsApi } = await import('../../src/services/bulkJobsApi.js')

describe('contactCaptureApi', () => {
	beforeEach(() => {
		axiosGet.mockReset()
		axiosPost.mockReset()
	})

	it('reads whether photo capture is on and its size limit', async () => {
		axiosGet.mockResolvedValue({ data: { photo: true, maxPhotoBytes: '2048' } })

		await expect(captureStatus()).resolves.toEqual({ photo: true, maxPhotoBytes: 2048 })
		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contact-capture/status')
	})

	it('treats anything but a literal true as photo off, and a bad limit as zero', async () => {
		axiosGet.mockResolvedValue({ data: { photo: 'yes', maxPhotoBytes: 'many' } })

		await expect(captureStatus()).resolves.toEqual({ photo: false, maxPhotoBytes: 0 })
	})

	it('answers photo off when the status cannot be read', async () => {
		axiosGet.mockRejectedValue(new Error('offline'))

		await expect(captureStatus()).resolves.toEqual({ photo: false, maxPhotoBytes: 0 })
	})

	it('posts pasted text and answers the proposal as is', async () => {
		const proposal = { fields: { email: 'a@example.org' } }
		axiosPost.mockResolvedValue({ data: proposal })

		await expect(parseText('A <a@example.org>')).resolves.toBe(proposal)
		expect(axiosPost).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/contact-capture/parse', { text: 'A <a@example.org>' })
	})

	it('posts a photo as the file field of a form', async () => {
		const file = new Blob(['jpeg'], { type: 'image/jpeg' })
		axiosPost.mockResolvedValue({ data: { fields: {} } })

		await expect(parsePhoto(file)).resolves.toEqual({ fields: {} })
		const [url, body] = axiosPost.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/api/contact-capture/parse')
		expect(body).toBeInstanceOf(FormData)
		expect(body.get('file')).toBeInstanceOf(Blob)
	})

	it('lets a parse failure reach the caller', async () => {
		axiosPost.mockRejectedValue(new Error('422'))

		await expect(parseText('')).rejects.toThrow('422')
	})
})

describe('bulkJobsApi', () => {
	const fetchMock = vi.fn()

	/**
	 * A fetch response.
	 *
	 * @param {boolean} ok - Whether the status is 2xx
	 * @param {Function} json - What json() does
	 * @return {object} The response
	 */
	const response = (ok, json) => ({ ok, json })

	beforeEach(() => {
		fetchMock.mockReset()
		vi.stubGlobal('fetch', fetchMock)
		vi.stubGlobal('OC', { requestToken: 'token-1' })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('lists the caller\'s jobs with the request token', async () => {
		fetchMock.mockResolvedValue(response(true, async () => ({ results: [{ id: 'j1' }] })))

		await expect(bulkJobsApi.list()).resolves.toEqual([{ id: 'j1' }])
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/openregister/api/bulk-jobs?limit=100', {
			method: 'GET',
			headers: { 'Content-Type': 'application/json', requesttoken: 'token-1' },
		})
	})

	it('answers an empty list when the answer has no results', async () => {
		fetchMock.mockResolvedValue(response(true, async () => ({})))

		await expect(bulkJobsApi.list()).resolves.toEqual([])
	})

	it('asks for a reversal and a commit by POST on the job', async () => {
		fetchMock.mockResolvedValue(response(true, async () => ({ id: 'j2', status: 'preview' })))

		await expect(bulkJobsApi.reverse('j2')).resolves.toEqual({ id: 'j2', status: 'preview' })
		await bulkJobsApi.commit('j2')

		expect(fetchMock.mock.calls.map(([url, init]) => [url, init.method])).toEqual([
			['/index.php/apps/openregister/api/bulk-jobs/j2/reverse', 'POST'],
			['/index.php/apps/openregister/api/bulk-jobs/j2/commit', 'POST'],
		])
	})

	it('throws the server\'s reason, else its error, on a refusal', async () => {
		fetchMock.mockResolvedValueOnce(response(false, async () => ({ reason: 'Not yours', error: 'forbidden' })))
		fetchMock.mockResolvedValueOnce(response(false, async () => ({ error: 'forbidden' })))

		await expect(bulkJobsApi.commit('j3')).rejects.toThrow('Not yours')
		await expect(bulkJobsApi.commit('j3')).rejects.toThrow('forbidden')
	})

	it('throws a generic message when a refusal has no readable body', async () => {
		fetchMock.mockResolvedValue(response(false, async () => { throw new SyntaxError('not json') }))

		await expect(bulkJobsApi.reverse('j4')).rejects.toThrow('Bulk change request failed')
	})
})
