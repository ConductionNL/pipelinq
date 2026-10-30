import { mount } from '@vue/test-utils'
// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqKlant / PqOrganisatie side cards: a section widget passes on the title
 * row its section reports, and Notities keeps its composer behind the
 * "Notitie toevoegen" header button, so the first note opens under a rule.
 * Contactkanalen and Relaties are library widgets (clientSideCardsLibraryWidgets).
 *
 * @spec openspec/changes/client-side-cards-title-add/specs/client-side-cards-title-add/spec.md
 */
import { describe, expect, it } from 'vitest'
import { h } from 'vue'
import { sectionWidget } from '../../src/components/widgets/sectionWidget.js'
import manifest from '../../src/manifest.json'

describe('sectionWidget forwards the header a section reports', () => {
	it('emits the section header on the widget', () => {
		const Reporter = {
			name: 'Reporter',
			emits: ['header'],
			mounted() {
				this.$emit('header', { actions: [{ label: 'Add', run: () => {} }] })
			},
			render() {
				return h('p')
			},
		}
		const Widget = sectionWidget('ReporterWidget', Reporter, () => ({}))
		const wrapper = mount(Widget, { props: { objectId: 'c-1', objectData: { id: 'c-1' } } })
		expect(wrapper.emitted('header')[0][0].actions[0].label).toBe('Add')
	})
})

describe('the client page wires the side cards', () => {
	const page = manifest.pages.find((p) => p.id === 'ClientDetail')
	const widget = (id) => page.config.widgets.find((w) => w.id === id)

	it('keeps the notes composer behind the header button', () => {
		expect(widget('client-notes').props).toMatchObject({ composer: 'button' })
	})
})
