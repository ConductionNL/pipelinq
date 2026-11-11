/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client, lead and request create dialogs start the same way, submit
 * through their form, and close before they open the created record.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {} }))
vi.mock('../../src/views/clients/ClientForm.vue', () => ({ default: {} }))
vi.mock('../../src/views/leads/LeadForm.vue', () => ({ default: {} }))
vi.mock('../../src/views/requests/RequestForm.vue', () => ({ default: {} }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let dialogs

beforeAll(async () => {
	globalThis.t = (app, text) => text
	dialogs = {
		client: (await import('../../src/dialogs/ClientCreateDialog.vue')).default,
		lead: (await import('../../src/dialogs/LeadCreateDialog.vue')).default,
		request: (await import('../../src/dialogs/RequestCreateDialog.vue')).default,
	}
}, 60000)

/**
 * @param {object} component A component, with the mixins it uses.
 * @return {object} Its state and methods, as one context.
 */
function componentCtx(component) {
	const ctx = {}
	for (const part of [...(component.mixins || []), component]) {
		Object.assign(ctx, part.methods || {}, part.data ? part.data.call({}) : {})
	}
	return ctx
}

/**
 * @param {object} component A component, with the mixins it uses.
 * @return {string[]} The events it declares.
 */
function emitsOf(component) {
	return [...(component.mixins || []), component].flatMap((part) => part.emits || [])
}

describe('the create dialogs', () => {
	it('start invalid and not saving, and declare created and close', () => {
		expect(componentCtx(dialogs.client)).toMatchObject({ valid: false, saving: false })
		expect(componentCtx(dialogs.lead)).toMatchObject({ valid: false, saving: false })
		expect(componentCtx(dialogs.request)).toMatchObject({ valid: false, saving: false, openAfter: true })
		for (const dialog of Object.values(dialogs)) {
			expect(new Set(emitsOf(dialog))).toEqual(new Set(['created', 'close']))
		}
	})

	it('submit through the form', () => {
		for (const dialog of Object.values(dialogs)) {
			const onSave = vi.fn()
			const ctx = { ...componentCtx(dialog), $refs: { form: { onSave } } }
			ctx.submit()
			expect(onSave).toHaveBeenCalledTimes(1)
		}
	})

	it('close, then open the created record when there is one and a router', () => {
		for (const dialog of Object.values(dialogs)) {
			const order = []
			const push = vi.fn(() => {
				order.push('push')
				return Promise.reject(new Error('dup'))
			})
			const ctx = { ...componentCtx(dialog), $emit: (name) => order.push(name), $router: { push } }
			ctx.goToDetail('LeadDetail', 'l1')
			expect(order).toEqual(['close', 'push'])
			expect(push).toHaveBeenCalledWith({ name: 'LeadDetail', params: { id: 'l1' } })
			ctx.goToDetail('LeadDetail', '')
			expect(push).toHaveBeenCalledTimes(1)
			const bare = { ...componentCtx(dialog), $emit: vi.fn(), $router: undefined }
			bare.goToDetail('LeadDetail', 'l1')
			expect(bare.$emit).toHaveBeenCalledWith('close')
		}
	})
})
