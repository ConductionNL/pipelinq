// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The shared preview-then-commit logic of the bulk dialogs: the footer's
 * props follow the dialog's state, Preview resolves the scope and asks the
 * host for its job, and a refused preview shows its reason.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-4.2
 */
import { describe, expect, it, vi } from 'vitest'

const scopes = vi.fn()

vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/services/bulkJobs.js', () => ({
	commitJob: vi.fn(),
	refusalReason: (error, fallback) => error.reason ?? fallback,
	resolveScope: (...a) => scopes(...a),
}))

const mixin = (await import('../../src/mixins/bulkPreviewJobMixin.js')).default

/**
 * A host dialog over a plain context.
 *
 * @param {object} state The host's state and its createPreviewJob.
 * @return {object} The context.
 */
function host(state) {
	const ctx = {
		register: 'pipelinq',
		schema: 'client',
		selectedIds: ['a'],
		preview: null,
		refusal: '',
		previewing: false,
		committing: false,
		canPreview: true,
		summary: { changed: 0, skipped: 0 },
		$options: { previewTestidPrefix: 'bulk-test' },
		t: (app, text) => text,
		...state,
	}
	for (const [name, method] of Object.entries(mixin.methods)) {
		ctx[name] = method.bind(ctx)
	}
	Object.defineProperty(ctx, 'previewActions', { get: () => mixin.computed.previewActions.call(ctx) })
	return ctx
}

describe('bulkPreviewJobMixin', () => {
	it('gives the footer the dialog state', () => {
		const ctx = host({ summary: { changed: 2, skipped: 1 } })
		expect(ctx.previewActions).toEqual({
			previewed: false, canPreview: true, previewing: false, committing: false, changed: 2, testidPrefix: 'bulk-test',
		})
		ctx.preview = { id: 'j1' }
		expect(ctx.previewActions.previewed).toBe(true)
		ctx.refusal = 'No'
		expect(ctx.previewActions.previewed).toBe(false)
	})

	it('previews on the resolved scope through the host', async () => {
		scopes.mockResolvedValue({ register: '5', schema: '7' })
		const createPreviewJob = vi.fn().mockResolvedValue({ id: 'j1' })
		const ctx = host({ createPreviewJob })

		await ctx.runPreview()

		expect(scopes).toHaveBeenCalledWith('pipelinq', 'client', 'a')
		expect(createPreviewJob).toHaveBeenCalledWith({ register: '5', schema: '7' })
		expect(ctx.preview).toEqual({ id: 'j1' })
		expect(ctx.previewing).toBe(false)
	})

	it('shows the reason a preview was refused', async () => {
		scopes.mockResolvedValue({ register: '5', schema: '7' })
		const ctx = host({ createPreviewJob: vi.fn().mockRejectedValue({ reason: 'Not allowed' }) })

		await ctx.runPreview()

		expect(ctx.refusal).toBe('Not allowed')
		expect(ctx.preview).toBeNull()
		expect(ctx.previewing).toBe(false)
	})
})
