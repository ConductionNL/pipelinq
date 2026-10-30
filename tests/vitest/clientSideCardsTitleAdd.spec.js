import { mount } from '@vue/test-utils'
// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqKlant / PqOrganisatie side cards: Contactkanalen and Relaties put their
 * add in the card's title row as the outlined card button ("Toevoegen"), the
 * channels one a menu of e-mail, phone and social profile; Notities keeps its
 * composer behind the "Notitie toevoegen" header button, so the first note
 * opens under a rule. The library card draws the title row from the `header`
 * event the section reports through its widget.
 *
 * @spec openspec/changes/client-side-cards-title-add/specs/client-side-cards-title-add/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { sectionWidget } from '../../src/components/widgets/sectionWidget.js'
import manifest from '../../src/manifest.json'

const { default: ContactChannelsSection } = await import('../../src/components/ContactChannelsSection.vue')
const { default: ContactRelationships } = await import('../../src/components/ContactRelationships.vue')
const registrySource = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

const t = (_app, text) => text

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

describe('the channels card reports its add menu', () => {
	it('reports one outlined Add with the three kinds as items', () => {
		const emitted = []
		const ctx = {
			t,
			$emit: (name, payload) => emitted.push([name, payload]),
			openEmailPhoneModal: vi.fn(),
			openSocialModal: vi.fn(),
		}
		ContactChannelsSection.methods.reportHeader.call(ctx)
		const [name, header] = emitted[0]
		expect(name).toBe('header')
		const [action] = header.actions
		expect(action).toMatchObject({ label: 'Add', ariaLabel: 'Add channel', icon: 'Plus', variant: 'secondary' })
		expect(action.items.map((i) => i.label)).toEqual(['Email address', 'Phone number', 'Social profile'])
		action.items[1].run()
		expect(ctx.openEmailPhoneModal).toHaveBeenCalledWith('phone', null)
		action.items[2].run()
		expect(ctx.openSocialModal).toHaveBeenCalledWith(null)
	})
})

describe('the relationships card reports its add button', () => {
	it('reports one outlined Add that opens the add dialog', () => {
		const emitted = []
		const ctx = { t, showAddDialog: false, $emit: (name, payload) => emitted.push([name, payload]) }
		ContactRelationships.methods.reportHeader.call(ctx)
		const [action] = emitted[0][1].actions
		expect(action).toMatchObject({ label: 'Add', ariaLabel: 'Add relationship', icon: 'Plus', variant: 'secondary' })
		action.run()
		expect(ctx.showAddDialog).toBe(true)
	})
})

describe('the client page wires the side cards', () => {
	const page = manifest.pages.find((p) => p.id === 'ClientDetail')
	const widget = (id) => page.config.widgets.find((w) => w.id === id)

	it('puts the channels and relationships in the library card with the add in its title row', () => {
		expect(widget('client-channels').type).toBe('ContactChannelsCard')
		expect(widget('client-channels').content.headerAdd).toBe(true)
		expect(widget('client-relationships').type).toBe('ContactRelationshipsCard')
		expect(widget('client-relationships').content.headerAdd).toBe(true)
		for (const name of ['ContactChannelsCard', 'ContactRelationshipsCard']) {
			const at = registrySource.indexOf(`\t${name}: {`)
			expect(at).toBeGreaterThan(-1)
			expect(registrySource.slice(at, registrySource.indexOf('\n\t},', at))).toContain("chrome: 'card'")
		}
	})

	it('keeps the notes composer behind the header button', () => {
		expect(widget('client-notes').props).toMatchObject({ composer: 'button' })
	})
})
