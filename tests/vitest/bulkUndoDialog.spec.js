// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The undo preview of a bulk change: how many records get their old value
 * back and how many are skipped (from either shape OpenRegister answers),
 * the refusal, and Cancel / Undo, both held while the commit runs.
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, 'data-variant': this.variant ?? 'default', onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'width'],
		emits: ['closing'],
		render() {
			return h('div', { 'data-name': this.name }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))

const { default: BulkUndoDialog } = await import('../../src/dialogs/BulkUndoDialog.vue')

const t = (app, text) => text
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

function mountDialog(props) {
	return mount(BulkUndoDialog, { props, global: { mocks: { t, n } } })
}

describe('BulkUndoDialog', () => {
	it('counts the restored and the skipped records from the nested counts', () => {
		const wrapper = mountDialog({ reversal: { total: 12, counts: { skipped: 2 } } })

		const lines = wrapper.findAll('p').map((p) => p.text())
		expect(lines).toEqual(['10 records get their old value back.', '2 records are skipped.'])
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('reads a flat skipped count, says nothing about skips at zero, and never goes below zero', () => {
		expect(mountDialog({ reversal: { total: 1, skipped: 0 } }).findAll('p').map((p) => p.text()))
			.toEqual(['1 record gets its old value back.'])
		expect(mountDialog({ reversal: { total: 1, skipped: 3 } }).findAll('p').map((p) => p.text()))
			.toEqual(['0 records get their old value back.', '3 records are skipped.'])
		expect(mountDialog({ reversal: { total: 'many', counts: { skipped: 'x' } } }).findAll('p').map((p) => p.text()))
			.toEqual(['0 records get their old value back.'])
	})

	it('shows the refusal and emits cancel and confirm from its buttons and from closing', async () => {
		const wrapper = mountDialog({ reversal: { total: 3 }, error: 'The reversal expired' })
		expect(wrapper.get('[role="alert"]').text()).toBe('The reversal expired')

		const [cancel, undo] = wrapper.findAll('button')
		await cancel.trigger('click')
		await undo.trigger('click')
		wrapper.getComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(undo.attributes('data-variant')).toBe('primary')
		expect(wrapper.emitted('cancel')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
	})

	it('holds both buttons while the commit runs', () => {
		const buttons = mountDialog({ reversal: { total: 3 }, busy: true }).findAll('button')

		expect(buttons.map((b) => b.attributes('disabled'))).toEqual(['', ''])
	})
})
