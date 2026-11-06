// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * A section widget's card carries the line beside its title from
 * `content.subtitle` (PqAfspraak "Wie en wat is geboekt, uit het tabblad
 * Resources"), through the page's translate.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: { name: 'CnDetailCard', props: ['title', 'subtitle'], template: '<section class="card" :data-title="title" :data-subtitle="subtitle"><slot /></section>' },
}))

const { sectionWidget } = await import('../../src/components/widgets/sectionWidget.js')

describe('a section widget card', () => {
	it('passes the content subtitle to the card, translated', () => {
		const Widget = sectionWidget('W', { template: '<p />' }, () => ({}), { card: true })
		const card = mount(Widget, {
			props: { objectId: 'b-1', content: { title: 'T', subtitle: 'S' } },
			global: { provide: { cnTranslate: (text) => text + '!' } },
		}).find('.card')
		expect(card.attributes('data-title')).toBe('T!')
		expect(card.attributes('data-subtitle')).toBe('S!')
	})
})
