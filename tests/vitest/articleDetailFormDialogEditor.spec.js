// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The article detail form's editor hand-off, the paths the save path does not
 * take: the editor closed without saving refreshes nothing, the editor
 * survives the form being re-shown, and a form without confirm or close
 * handlers still closes and confirms without failing.
 *
 * @spec openspec/specs/fe-dialogs/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const bus = vi.hoisted(() => ({ emit: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['schema', 'item', 'excludeFields', 'dialogTitle'],
		emits: ['confirm', 'close'],
		data: () => ({ lastResult: null }),
		methods: { setResult(result) { this.lastResult = result } },
		render() { return h('div', { class: 'form-dialog' }, this.$slots['before-fields']?.()) },
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('../../src/modals/ArticleEditModal.vue', () => ({
	default: { name: 'ArticleEditModal', props: ['article'], emits: ['close', 'saved'], render: () => h('div', { class: 'editor' }) },
}))

globalThis.t = (app, text) => text
const { default: Dialog } = await import('../../src/dialogs/ArticleDetailFormDialog.vue')

const schema = { id: 'article' }
const item = { id: 'a-1', title: 'FAQ', body: 'Text' }
const mountDialog = (props) => mount(Dialog, { props: { show: true, schema, item, ...props }, global: { mocks: { t: globalThis.t } } })

beforeEach(() => {
	bus.emit.mockReset()
})

describe('ArticleDetailFormDialog editor hand-off', () => {
	it('closes the editor without refreshing the page when it is dismissed', async () => {
		const w = mountDialog()
		await w.find('button').trigger('click')
		expect(w.find('.editor').exists()).toBe(true)
		w.findComponent({ name: 'ArticleEditModal' }).vm.$emit('close')
		await w.vm.$nextTick()
		expect(w.find('.editor').exists()).toBe(false)
		expect(bus.emit).not.toHaveBeenCalled()
		expect(w.find('.form-dialog').exists()).toBe(true)
	})

	it('closes the editor when the form is hidden, and leaves it alone when shown', async () => {
		const w = mountDialog()
		await w.find('button').trigger('click')
		await w.setProps({ show: false })
		expect(w.vm.editorOpen).toBe(false)
		w.vm.editorOpen = true
		await w.setProps({ show: true, item: { ...item, title: 'FAQ 2' } })
		expect(w.vm.editorOpen).toBe(true)
		expect(w.findComponent({ name: 'ArticleEditModal' }).props('article')).toEqual({ ...item, title: 'FAQ 2' })
	})

	it('never opens the editor without an article', async () => {
		const w = mountDialog({ item: null })
		w.vm.editorOpen = true
		await w.vm.$nextTick()
		expect(w.find('.editor').exists()).toBe(false)
		expect(w.find('button').exists()).toBe(false)
	})

	it('closes and confirms without handlers from the page', async () => {
		const w = mountDialog()
		const form = w.findComponent({ name: 'CnFormDialog' })
		expect(() => form.vm.$emit('close')).not.toThrow()
		form.vm.$emit('confirm', { slug: 'faq' })
		await flushPromises()
		expect(form.vm.lastResult).toBe(null)
	})
})
