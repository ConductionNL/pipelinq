// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The block editor of an email template (marketing-block-editor, tasks 2.1
 * and 3.1). The list itself is nextcloud-vue CnBlockListEditor, stubbed here:
 * these tests cover what pipelinq hands it (the palette, new blocks, the
 * per-type editors) and the footer, which stays under the list, always last,
 * and keeps its two tokens.
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnBlockListEditor: {
		name: 'CnBlockListEditor',
		props: ['modelValue', 'types', 'labels', 'collapsible'],
		emits: ['update:modelValue'],
		render() {
			const update = (index) => (changes) => this.$emit('update:modelValue', this.modelValue.map((b, i) => (i === index ? { ...b, ...changes } : b)))
			const add = (option) => this.$emit('update:modelValue', [...this.modelValue, { id: `new-${option.type}`, type: option.type, ...option.create() }])
			return h('div', [
				...this.types.map((option) => h('button', { 'data-add': option.type, onClick: () => add(option) }, option.label)),
				...this.modelValue.map((block, index) => {
					const slot = this.$slots[`block-${block.type}`] || this.$slots.block
					return h('div', { 'data-block': block.type }, [this.$slots.summary({ block }), slot({ block, update: update(index) })])
				}),
			])
		},
	},
	CnMarkdownEditor: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('textarea', { 'data-md': '1', value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
}))
vi.mock('@nextcloud/vue', () => {
	const text = (tag) => ({
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() {
			return h(tag, { 'data-label': this.label, value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	})
	return { NcTextArea: text('textarea'), NcTextField: text('input') }
})

const { default: MailBlockEditor } = await import('../../src/components/templates/MailBlockEditor.vue')
const { starterBlocks } = await import('../../src/services/mailBlocks.js')

/**
 * Mount the editor and keep its v-model in step, as the dialog does.
 *
 * @param {Array<object>} blocks The starting blocks.
 * @return {object} The wrapper.
 */
function mountEditor(blocks) {
	const wrapper = mount(MailBlockEditor, {
		props: {
			modelValue: blocks,
			'onUpdate:modelValue': (value) => wrapper.setProps({ modelValue: value }),
		},
		global: { mocks: { t: (_app, text) => text } },
	})
	return wrapper
}

const types = (wrapper) => wrapper.props('modelValue').map((b) => b.type)

describe('MailBlockEditor', () => {
	it('hands the list every block but the footer, and offers the seven block types', () => {
		const wrapper = mountEditor(starterBlocks())
		const list = wrapper.findComponent({ name: 'CnBlockListEditor' })
		expect(list.props('modelValue').map((b) => b.type)).toEqual(['heading', 'text'])
		expect(list.props('types').map((o) => o.label)).toEqual(['Heading', 'Text', 'Image', 'Button', 'Divider', 'Spacer', 'Articles'])
		expect(list.props('labels')).toEqual({ add: 'Add a block', list: 'Blocks' })
	})

	it('adds a block above the footer with empty content for its type', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper.find('[data-add="button"]').trigger('click')
		await flushPromises()
		expect(types(wrapper)).toEqual(['heading', 'text', 'button', 'footer'])
		expect(wrapper.props('modelValue')[2].props).toEqual({ label: '', href: '', color: '' })
		expect(wrapper.find('[data-label="Button text"]').exists()).toBe(true)
	})

	it('writes a typed property into the block it belongs to', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper.find('[data-label="Heading text"]').setValue('Nieuws uit Zuiddrecht')
		expect(wrapper.props('modelValue')[0].props).toEqual({ text: 'Nieuws uit Zuiddrecht', level: 2 })
		expect(wrapper.find('[data-block="heading"]').text()).toContain('Nieuws uit Zuiddrecht')
	})

	it('keeps both tokens after whatever the marketer types in the footer', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper.find('[data-label="Footer text"]').setValue('Sent by Zuiddrecht')
		const footer = wrapper.props('modelValue').at(-1)
		expect(footer.props.text).toBe('Sent by Zuiddrecht\n{{physical_address}}\n{{unsubscribe_link}}')
		expect(wrapper.find('[data-label="Footer text"]').element.value).toBe('Sent by Zuiddrecht')
		expect(types(wrapper)).toEqual(['heading', 'text', 'footer'])
	})

	it('says what the articles block shows, as it has nothing to set', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper.find('[data-add="articles"]').trigger('click')
		expect(wrapper.find('[data-block="articles"]').text()).toContain('The articles picked below appear here.')
	})
})
