/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two small mixin paths no view spec reaches: choosing a receipt template
 * reloads the preview with it, and a form that is not there yet counts as
 * empty when the touched-fields watcher takes its first snapshot.
 *
 * @spec openspec/specs/client-forms/spec.md#requirement-errors-wait-for-the-user
 */
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/services/receiptTemplates.js', () => ({ loadActiveReceiptTemplates: vi.fn(async () => []) }))

describe('choosing a receipt template', () => {
	it('keeps the choice and reloads the preview with it', async () => {
		const mixin = (await import('../../src/mixins/receiptModalMixin.js')).default
		const template = { id: 'tpl-2', label: 'Kort' }
		const ctx = { ...mixin.data(), fetchPreview: vi.fn(async () => ({ text: 'Bon met Kort' })), showPreview: vi.fn() }
		ctx.loadPreview = mixin.methods.loadPreview.bind(ctx)
		await mixin.methods.selectTemplate.call(ctx, template)
		expect(ctx.selectedTemplate).toBe(template)
		expect(ctx.fetchPreview).toHaveBeenCalledTimes(1)
		expect(ctx.previewText).toBe('Bon met Kort')
		expect(ctx.showPreview).toHaveBeenCalledWith({ text: 'Bon met Kort' })
		expect(ctx.previewLoading).toBe(false)
	})
})

describe('the touched-fields watcher', () => {
	it('snapshots a missing form as empty and marks the fields typed after it', async () => {
		const mixin = (await import('../../src/mixins/touchedErrorsMixin.js')).default
		const handler = mixin.watch.form.handler
		const ctx = mixin.data()
		handler.call(ctx, null)
		expect(ctx.lastFormSnapshot).toEqual({})
		expect(ctx.touchedFields).toEqual({})
		handler.call(ctx, { name: 'Acme', email: '' })
		expect(ctx.touchedFields).toEqual({ name: true, email: true })
	})
})
