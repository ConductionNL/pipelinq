// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * KpiFigure: a figure drawn as a KPI, the number large with a line under it.
 * The PqResource "This week" card draws "22" with "bookings, 74% of the
 * bookable time" under it.
 *
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import KpiFigure from '../../src/components/KpiFigure.vue'

describe('KpiFigure', () => {
	it('draws the value large and the caption under it', () => {
		const w = mount(KpiFigure, { props: { value: 22, caption: 'bookings, 74% of the bookable time' } })
		const value = w.find('.kpi-figure__value')
		const caption = w.find('.kpi-figure__caption')
		expect(value.element.tagName).toBe('STRONG')
		expect(value.text()).toBe('22')
		expect(caption.text()).toBe('bookings, 74% of the bookable time')
		expect(value.element.compareDocumentPosition(caption.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
	})

	it('leaves the caption out when there is none', () => {
		expect(mount(KpiFigure, { props: { value: 0 } }).find('.kpi-figure__caption').exists()).toBe(false)
	})
})

describe('the resource "This week" card', () => {
	const src = readFileSync(resolve(__dirname, '../../src/views/bookings/ResourceDetail.vue'), 'utf8')

	it('draws the week count as a KPI figure with the share as its caption', () => {
		expect(src).toMatch(/<KpiFigure\s+:value="weekLoad\.count"\s+:caption="weekCaption"/)
	})
})
