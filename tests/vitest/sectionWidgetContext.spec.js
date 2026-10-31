// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * A section that reads the page's record from `cnSectionContext` works as a
 * tab widget: `sectionWidget(..., { context: true })` provides the record the
 * detail page hands every widget (board PqTicket, the Overview stack).
 *
 * @spec openspec/changes/simple-ticket-page/specs/ticket-detail-page/spec.md#REQ-TDP-003
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { h, inject, unref } from 'vue'
import { sectionWidget } from '../../src/components/widgets/sectionWidget.js'

const Reader = {
	name: 'Reader',
	setup() {
		const context = inject('cnSectionContext', null)
		return () =>
			h(
				'p',
				{ 'data-testid': 'reader' },
				unref(context)
					? `${unref(context).objectId}:${unref(context).object?.title}`
					: 'none',
			)
	},
}

describe('sectionWidget context', () => {
	it('provides the record and its id as cnSectionContext when asked to', async () => {
		const Widget = sectionWidget('ReaderWidget', Reader, () => ({}), {
			context: true,
		})
		const wrapper = mount(Widget, {
			props: { objectId: 't-1', objectData: { id: 't-1', title: 'Permit' } },
		})
		expect(wrapper.find('[data-testid="reader"]').text()).toBe('t-1:Permit')
		await wrapper.setProps({
			objectData: { id: 't-1', title: 'Permit, answered' },
		})
		expect(wrapper.find('[data-testid="reader"]').text()).toBe(
			't-1:Permit, answered',
		)
	})

	it('provides nothing without the option', () => {
		const Widget = sectionWidget('ReaderWidget', Reader, () => ({}))
		const wrapper = mount(Widget, {
			props: { objectId: 't-1', objectData: { id: 't-1' } },
		})
		expect(wrapper.find('[data-testid="reader"]').text()).toBe('none')
	})
})
