// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The receipt preview in the print and email dialogs (PqKassaAfrekenen) shows
 * the receipt as typed, through nextcloud-vue CnTextWidget `monospace`.
 *
 * @spec openspec/changes/adopt-library-receipt-text/specs/pos-receipt-engine/spec.md#requirement-the-receipt-preview-shows-the-text-as-typed
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnTextWidget: { name: 'CnTextWidget', props: ['content', 'objectData'], template: '<pre>{{ objectData[content.textField] }}</pre>' },
}))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))

const { default: ReceiptPreviewPane } = await import('../../src/components/pos/ReceiptPreviewPane.vue')

const mountPane = (props) => mount(ReceiptPreviewPane, { props, global: { mocks: { t: (_app, text) => text } } })

describe('ReceiptPreviewPane', () => {
	it('hands the receipt text to the text widget as typed, in a fixed-width font', () => {
		const receipt = 'Bakkerij De Graaf\n2x Brood      5,90\nTotaal        5,90'
		const widget = mountPane({ content: receipt }).findComponent({ name: 'CnTextWidget' })
		expect(widget.props('content')).toEqual({ monospace: true, textField: 'receipt' })
		expect(widget.props('objectData')).toEqual({ receipt })
	})

	it('says there is no preview when the text is empty', () => {
		const widget = mountPane({ content: '' }).findComponent({ name: 'CnTextWidget' })
		expect(widget.text()).toBe('No preview available')
	})

	it('shows a spinner instead of the text while the preview loads', () => {
		const pane = mountPane({ content: 'x', loading: true })
		expect(pane.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		expect(pane.findComponent({ name: 'CnTextWidget' }).exists()).toBe(false)
	})
})
