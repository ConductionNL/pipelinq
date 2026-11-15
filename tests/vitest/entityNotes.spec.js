// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Notes on an entity: the requests made, when a note is sent, and how a time is told.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', () => ({ NcButton: { name: 'NcButton', render: () => null }, NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null } }))

/**
 * The test translator.
 *
 * @param {string} app The app.
 * @param {string} text The source.
 * @return {string} The text.
 */
function t(app, text) {
	return text
}

/**
 * The test plural translator, %n filled.
 *
 * @param {string} app The app.
 * @param {string} one The singular.
 * @param {string} many The plural.
 * @param {number} count The count.
 * @return {string} The text.
 */
function n(app, one, many, count) {
	return (count === 1 ? one : many).replace('%n', String(count))
}

const { default: EntityNotes } = await import('../../src/components/EntityNotes.vue')

/**
 * A fetch response.
 *
 * @param {boolean} ok Whether it succeeded.
 * @param {object} body The JSON body.
 * @return {object} The response.
 */
function answer(ok, body = {}) {
	return { ok, json: async () => body }
}

describe('EntityNotes', () => {
	let fetchMock

	beforeEach(() => {
		globalThis.OC = { requestToken: 'tok' }
		globalThis.t = t
		globalThis.n = n
		fetchMock = vi.fn()
		globalThis.fetch = fetchMock
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	/**
	 * Mount on a lead and let the first read settle.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function notes() {
		const w = mount(EntityNotes, { props: { objectType: 'lead', objectId: 'l-1' }, global: { mocks: { t, n } } })
		await flushPromises()
		return w
	}

	it('reads the notes of the entity with the JSON headers', async () => {
		fetchMock.mockResolvedValue(answer(true, { notes: [{ id: 1, message: 'Gebeld' }] }))
		const w = await notes()

		expect(fetchMock).toHaveBeenCalledWith('/apps/pipelinq/api/notes/lead/l-1', {
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
		expect(w.vm.notes).toEqual([{ id: 1, message: 'Gebeld' }])
		expect(w.vm.loading).toBe(false)
	})

	it('keeps an empty list for an answer without notes, a refusal, or a failure', async () => {
		fetchMock.mockResolvedValue(answer(true, {}))
		expect((await notes()).vm.notes).toEqual([])

		fetchMock.mockResolvedValue(answer(false))
		expect((await notes()).vm.notes).toEqual([])

		fetchMock.mockImplementation(() => Promise.reject(new Error('offline')))
		const failed = await notes()
		expect(failed.vm.notes).toEqual([])
		expect(failed.vm.loading).toBe(false)
	})

	it('reads again when the entity changes', async () => {
		fetchMock.mockResolvedValue(answer(true, { notes: [] }))
		const w = await notes()
		await w.setProps({ objectId: 'l-2' })
		await flushPromises()

		expect(fetchMock).toHaveBeenLastCalledWith('/apps/pipelinq/api/notes/lead/l-2', expect.any(Object))
	})

	it('posts a note, clears the field and reads again; a blank note is not sent', async () => {
		fetchMock.mockResolvedValue(answer(true, { notes: [] }))
		const w = await notes()
		fetchMock.mockClear()

		w.vm.newMessage = '   '
		await w.vm.addNote()
		expect(fetchMock).not.toHaveBeenCalled()

		w.vm.newMessage = 'Offerte gestuurd'
		await w.vm.addNote()
		expect(fetchMock.mock.calls[0]).toEqual([
			'/apps/pipelinq/api/notes/lead/l-1',
			{ method: 'POST', headers: expect.any(Object), body: JSON.stringify({ message: 'Offerte gestuurd' }) },
		])
		expect(fetchMock).toHaveBeenCalledTimes(2)
		expect(w.vm.newMessage).toBe('')
		expect(w.vm.submitting).toBe(false)
	})

	it('keeps the message when the post is refused or fails', async () => {
		fetchMock.mockResolvedValue(answer(true, { notes: [] }))
		const w = await notes()
		w.vm.newMessage = 'Offerte gestuurd'

		fetchMock.mockResolvedValueOnce(answer(false))
		await w.vm.addNote()
		expect(w.vm.newMessage).toBe('Offerte gestuurd')

		fetchMock.mockImplementationOnce(() => Promise.reject(new Error('offline')))
		await w.vm.addNote()
		expect(w.vm.newMessage).toBe('Offerte gestuurd')
		expect(w.vm.submitting).toBe(false)
	})

	it('deletes a note by id and reads again only when that worked', async () => {
		fetchMock.mockResolvedValue(answer(true, { notes: [] }))
		const w = await notes()
		fetchMock.mockClear()

		await w.vm.deleteNote(9)
		expect(fetchMock.mock.calls[0]).toEqual(['/apps/pipelinq/api/notes/single/9', { method: 'DELETE', headers: expect.any(Object) }])
		expect(fetchMock).toHaveBeenCalledTimes(2)

		fetchMock.mockClear()
		fetchMock.mockResolvedValueOnce(answer(false))
		await w.vm.deleteNote(9)
		expect(fetchMock).toHaveBeenCalledTimes(1)

		fetchMock.mockImplementationOnce(() => Promise.reject(new Error('offline')))
		await expect(w.vm.deleteNote(9)).resolves.toBeUndefined()
	})

	it('tells a time as just now, minutes, hours, then a date', async () => {
		fetchMock.mockResolvedValue(answer(true, { notes: [] }))
		const w = await notes()
		vi.useFakeTimers()
		vi.setSystemTime(new Date('2026-11-12T12:00:00Z'))

		expect(w.vm.formatTime(null)).toBe('')
		expect(w.vm.formatTime('2026-11-12T11:59:30Z')).toBe('Just now')
		expect(w.vm.formatTime('2026-11-12T11:59:00Z')).toBe('1 minute ago')
		expect(w.vm.formatTime('2026-11-12T11:15:00Z')).toBe('45 minutes ago')
		expect(w.vm.formatTime('2026-11-12T09:00:00Z')).toBe('3 hours ago')
		expect(w.vm.formatTime('2026-11-10T09:00:00Z')).toContain('2026')
	})
})
