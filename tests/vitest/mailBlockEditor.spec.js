// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The block editor of an email template (marketing-block-editor, tasks 2.1
 * and 3.1): blocks are added with a button, reordered with Move up and Move
 * down, removed except the footer, and the footer keeps its two tokens.
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-a-marketer-builds-an-email-template-from-blocks-req-mbe-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnMarkdownEditor: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('textarea', {
				'data-md': '1',
				value: this.modelValue,
				onInput: (e) => this.$emit('update:modelValue', e.target.value),
			})
		},
	},
}))
vi.mock('@nextcloud/vue', () => {
	const text = (tag) => ({
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() {
			return h(tag, {
				'data-label': this.label,
				value: this.modelValue,
				onInput: (e) => this.$emit('update:modelValue', e.target.value),
			})
		},
	})
	return {
		NcButton: {
			props: ['variant', 'disabled', 'ariaLabel'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{
						'aria-label': this.ariaLabel,
						'data-testid': this.$attrs['data-testid'],
						disabled: this.disabled,
						onClick: () => this.$emit('click'),
					},
					this.$slots.default?.(),
				)
			},
		},
		NcTextArea: text('textarea'),
		NcTextField: text('input'),
	}
})

const { default: MailBlockEditor } =
	await import('../../src/components/templates/MailBlockEditor.vue')
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
			'onUpdate:modelValue': (value) =>
				wrapper.setProps({ modelValue: value }),
		},
		global: {
			mocks: {
				t: (_app, text, vars = {}) =>
					text.replace(/\{(\w+)\}/g, (_m, k) => vars[k]),
			},
		},
		attachTo: document.body,
	})
	return wrapper
}

const types = (wrapper) => wrapper.props('modelValue').map((b) => b.type)

describe('MailBlockEditor', () => {
	it('adds a block above the footer with the palette button', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper.find('[data-testid="mail-block-add-button"]').trigger('click')
		await flushPromises()
		expect(types(wrapper)).toEqual(['heading', 'text', 'button', 'footer'])
		expect(wrapper.find('[data-label="Button text"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('moves the text block above the heading and keeps focus on it', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper.find('[aria-label="Move Text up"]').trigger('click')
		await flushPromises()
		expect(types(wrapper)).toEqual(['text', 'heading', 'footer'])
		// At the top, Move up is disabled, so focus is on its Move down.
		expect(document.activeElement.getAttribute('aria-label')).toBe(
			'Move Text down',
		)
		wrapper.unmount()
	})

	it('offers no remove, move or drag handle on the footer', async () => {
		const wrapper = mountEditor(starterBlocks())
		const footer = wrapper.find('[data-testid="mail-block-footer"]')
		expect(footer.find('[aria-label="Remove Footer"]').exists()).toBe(false)
		expect(footer.find('.mail-block-editor__handle').exists()).toBe(false)
		await wrapper.find('[aria-label="Remove Heading"]').trigger('click')
		expect(types(wrapper)).toEqual(['text', 'footer'])
		wrapper.unmount()
	})

	it('keeps both tokens after whatever the marketer types in the footer', async () => {
		const wrapper = mountEditor(starterBlocks())
		await wrapper
			.find('[data-testid="mail-block-footer"] .mail-block-editor__select')
			.trigger('click')
		await wrapper
			.find('[data-label="Footer text"]')
			.setValue('Sent by Zuiddrecht')
		const footer = wrapper.props('modelValue').at(-1)
		expect(footer.props.text).toBe(
			'Sent by Zuiddrecht\n{{physical_address}}\n{{unsubscribe_link}}',
		)
		expect(wrapper.find('[data-label="Footer text"]').element.value).toBe(
			'Sent by Zuiddrecht',
		)
		wrapper.unmount()
	})
})
