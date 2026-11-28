// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Start request dashboard widget stores the category's stable value, not
 * its translated label: a Dutch user wrote "Algemeen" into `category`.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-55
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/components/widgets/ClientAutocomplete.vue', () => ({ default: {} }))

let widget

beforeAll(async () => {
	// A Dutch reader: every label comes back translated.
	globalThis.t = (app, text) => ({ General: 'Algemeen', Technical: 'Technisch' })[text] || text
	globalThis.OC = { requestToken: 'token' }
	widget = (await import('../../src/views/widgets/StartRequestWidget.vue')).default
})

describe('StartRequestWidget', () => {
	it('offers every category with a stable value', () => {
		const { categoryOptions } = widget.data.call({})
		expect(categoryOptions.map((o) => o.id)).toEqual(['general', 'technical', 'billing', 'support'])
		expect(categoryOptions[0].label).toBe('Algemeen')
	})

	it('saves the value, not the translated label', async () => {
		const state = widget.data.call({})
		const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ id: 'r1' }) })
		globalThis.fetch = fetchMock
		const ctx = {
			...state,
			config: { ticket: { register: 'pipelinq', schema: 'ticket' } },
			form: { title: 'Broken link', category: state.categoryOptions[0], priority: 'normal', channel: null },
		}
		await widget.methods.onSubmit.call(ctx)
		const body = JSON.parse(fetchMock.mock.calls[0][1].body)
		expect(body.category).toBe('general')
	})
})
