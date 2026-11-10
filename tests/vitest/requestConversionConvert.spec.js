// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Converting a request to a case: the request it posts for the dialog's
 * choice, what the section shows afterwards, and what it says for each
 * refusal.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => url.replace('{id}', params?.id) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		props: ['name', 'eyebrow', 'subtitle', 'width'],
		render() {
			return h('div', { class: 'cn-dialog', 'data-eyebrow': this.eyebrow, 'data-subtitle': this.subtitle }, [this.name, this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	CnNoteCard: { props: ['text', 'type'], render() { return h('p', { class: 'cn-note', 'data-type': this.type }, this.text) } },
	CnResourceSelect: {
		props: ['modelValue', 'register', 'schema'],
		emits: ['update:modelValue'],
		render() {
			return h('select', { 'data-register': this.register, onChange: () => this.$emit('update:modelValue', 'b6c1f5a2-0d3e-4a7b-9c8d-2e1f0a9b8c7d') })
		},
	},
}))
vi.mock('@nextcloud/vue', () => {
	const button = {
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, [this.$slots.default?.()])
		},
	}
	const checkbox = {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('input', { type: 'checkbox', onChange: () => this.$emit('update:modelValue', !this.modelValue) })
		},
	}
	return { NcButton: button, NcCheckboxRadioSwitch: checkbox, NcLoadingIcon: { render: () => h('span') }, NcNoteCard: { render() { return h('div', this.$slots.default?.()) } } }
})

let Section

beforeAll(async () => {
	// The section uses Nextcloud's global t(), as it does in the browser.
	globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
	Section = (await import('../../src/views/requests/RequestConversionSection.vue')).default
})

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	store.fetchObject.mockReset()
})

const global = { mixins: [{ methods: { t: (app, text) => text } }] }

describe('RequestConversionSection.convertToCase', () => {
	const available = { data: { available: true, status: 'in_progress', canConvert: true, caseReference: '' } }

	async function section() {
		http.get.mockResolvedValue(available)
		store.fetchObject.mockResolvedValue(null)
		const w = mount(Section, { props: { requestId: 't1' }, global })
		await flushPromises()
		dialogs.showError.mockReset()
		dialogs.showSuccess.mockReset()
		http.get.mockClear()
		return w
	}

	it('posts the case type and the two choices, then shows the case', async () => {
		const w = await section()
		http.post.mockResolvedValueOnce({ data: { caseReference: 'case-9' } })
		w.vm.dialogOpen = true
		await w.vm.convertToCase({ caseType: 'permit', keepOpen: 1, informCustomer: 0 })
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/handoff/request/t1/convert-to-case', { caseType: 'permit', keepOpen: true, informCustomer: false })
		expect(w.vm.availability).toMatchObject({ status: 'converted', canConvert: false, caseReference: 'case-9' })
		expect(w.vm.dialogOpen).toBe(false)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Request converted to case.')
		expect(w.emitted('converted')).toEqual([[{ caseReference: 'case-9' }]])
		expect(w.vm.busy).toBe(false)
	})

	it('posts no case type without one, and reads a missing reference as empty', async () => {
		const w = await section()
		http.post.mockResolvedValueOnce({ data: {} })
		await w.vm.convertToCase()
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/handoff/request/t1/convert-to-case', { keepOpen: false, informCustomer: false })
		expect(w.emitted('converted')).toEqual([[{ caseReference: '' }]])
		http.post.mockResolvedValueOnce({ data: {} })
		await w.vm.convertToCase(null)
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/handoff/request/t1/convert-to-case', { keepOpen: false, informCustomer: false })
	})

	it('says why it failed, and reloads the availability when conversion is gone', async () => {
		const w = await section()
		const cases = [
			[{ response: { data: { status: 'invalid-status' } } }, 'Conversion is no longer available for this request.', 1],
			[{ response: { data: { status: 'not-available' } } }, 'Conversion is no longer available for this request.', 1],
			[{ response: { data: { status: 'handoff-failed', reason: 'No zaaktype' } } }, 'Could not create the case: No zaaktype', 0],
			[{ response: { data: { status: 'handoff-failed' } } }, 'Could not create the case: unknown error', 0],
			[new Error('x'), 'Could not convert this request to a case.', 0],
			[null, 'Could not convert this request to a case.', 0],
		]
		for (const [failure, message, reloads] of cases) {
			http.get.mockClear()
			http.post.mockRejectedValueOnce(failure)
			await w.vm.convertToCase({})
			expect(dialogs.showError).toHaveBeenLastCalledWith(message)
			expect(http.get).toHaveBeenCalledTimes(reloads)
			expect(w.vm.busy).toBe(false)
		}
		expect(w.emitted('converted')).toBeUndefined()
	})

	it('does nothing while busy or without a request', async () => {
		const w = await section()
		http.post.mockClear()
		w.vm.busy = true
		await w.vm.convertToCase({})
		expect(http.post).not.toHaveBeenCalled()
	})
})
