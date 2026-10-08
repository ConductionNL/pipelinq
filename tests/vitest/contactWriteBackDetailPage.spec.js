/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client Edit dialog on the client page writes a name, email or phone
 * change back to the Nextcloud Contact (round3-review-points, review point 1).
 *
 * The page is a manifest detail page. It saves through the library's default
 * object store, under the type `pipelinq-client`, so the write-back plugin
 * that only sat on pipelinq's own store and only knew `client` never ran.
 *
 * @spec openspec/changes/round3-review-points/specs/client-forms/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import {
	contactWriteBackPlugin,
	needsWriteBack,
	partyType,
} from '../../src/store/plugins/contactWriteBack.js'

vi.mock('../../src/services/contactSyncApi.js', () => ({ writeBack: vi.fn() }))

/**
 * Run one saveObject through the plugin and return what it wrote back.
 *
 * @param {string} type The store type.
 * @param {object} data The payload.
 * @return {Array} The write calls.
 */
function saveThrough(type, data) {
	const write = vi.fn()
	let listener = null
	contactWriteBackPlugin(write).setup({
		$onAction: (fn) => {
			listener = fn
		},
	})
	let afterFn = () => {}
	listener({
		name: 'saveObject',
		args: [type, data],
		after: (fn) => {
			afterFn = fn
		},
	})
	afterFn({ id: data.id })
	return write.mock.calls
}

describe('contact write-back from the client page', () => {
	it('knows the library store type of a client and a contact', () => {
		expect(partyType('pipelinq-client')).toBe('client')
		expect(partyType('pipelinq-contact')).toBe('contact')
		expect(partyType('client')).toBe('client')
		expect(partyType('pipelinq-lead')).toBeNull()
		expect(needsWriteBack('pipelinq-client', { id: 'c', phone: '020' })).toBe(
			true,
		)
	})

	it('writes back a phone saved under pipelinq-client, with the bare type', () => {
		expect(saveThrough('pipelinq-client', { id: 'c-1', phone: '020' })).toEqual([
			['client', 'c-1'],
		])
	})

	it('listens on the library default store as well', () => {
		const main = fs.readFileSync(
			path.resolve(__dirname, '../../src/main.js'),
			'utf8',
		)
		expect(main).toMatch(
			/contactWriteBackPlugin\(\)\.setup\(useLibraryObjectStore\(\)\)/,
		)
	})
})
