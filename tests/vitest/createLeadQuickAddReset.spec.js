// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Create lead widget's quick add (only with a title) and its reset, which
 * puts the first pipeline back and clears the success state.
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/components/widgets/ClientAutocomplete.vue', () => ({ default: {} }))

let widget

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'token' }
	widget = (await import('../../src/views/widgets/CreateLeadWidget.vue')).default
}, 60000)

describe('CreateLeadWidget quick add', () => {
	it('submits only when a title was typed', () => {
		const onSubmit = vi.fn()

		widget.methods.onQuickAdd.call({ form: { title: '' }, onSubmit })
		expect(onSubmit).not.toHaveBeenCalled()

		widget.methods.onQuickAdd.call({ form: { title: 'New lead' }, onSubmit })
		expect(onSubmit).toHaveBeenCalledTimes(1)
	})
})

describe('CreateLeadWidget reset', () => {
	it('restores the first pipeline and clears the client and success state', () => {
		const first = { id: 'p1' }
		const ctx = {
			pipelineOptions: [first, { id: 'p2' }],
			form: { title: 'x', pipeline: { id: 'p2' }, value: '100', source: 'web' },
			selectedClient: { id: 'c-1' },
			submitted: true,
			success: true,
			successLink: '/apps/pipelinq/leads/1',
		}

		widget.methods.resetForm.call(ctx)

		expect(ctx.form).toEqual({ title: '', pipeline: first, value: '', source: null })
		expect(ctx.selectedClient).toBeNull()
		expect([ctx.submitted, ctx.success, ctx.successLink]).toEqual([false, false, ''])
	})

	it('leaves the pipeline empty when there is none', () => {
		const ctx = { pipelineOptions: [], form: {}, selectedClient: null }

		widget.methods.resetForm.call(ctx)

		expect(ctx.form.pipeline).toBeNull()
	})
})
