// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A person's preferred name shows where an agent addresses them: first in the
 * screen pop's list of matched callers, and under the full name in the header
 * of the contact and client pages.
 *
 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import ScreenPopModal from '../../src/modals/ScreenPopModal.vue'
import manifest from '../../src/manifest.json'

vi.mock('@nextcloud/vue', () => {
	const shell = (name, tag) => ({
		name,
		render() {
			return h(tag, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
	return {
		NcButton: shell('NcButton', 'button'),
		NcDialog: shell('NcDialog', 'div'),
	}
})

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'subtitle', 'width'],
		render() {
			return h('div', { 'data-subtitle': this.subtitle }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))

globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)

/**
 * Mount the screen pop over a list of matches.
 *
 * @param {Array<object>} matches The matched callers.
 * @return {object} The wrapper.
 */
function screenPop(matches) {
	return mount(ScreenPopModal, {
		props: { matches, e164: '+31612345678' },
		global: { mocks: { t: (_app, text) => text } },
	})
}

/**
 * A page of the bundled manifest.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	return manifest.pages.find((p) => p.id === id)
}

describe('the screen pop as board PqInkomendGesprek draws it', () => {
	it('names the number as read, each match\'s client line and kind, and offers a new contact', () => {
		const wrapper = mount(ScreenPopModal, {
			props: {
				matches: [
					{ id: 'c-1', name: 'Sanne de Vries', type: 'person', address: 'Inwoner, Parkstraat 24', _matchType: 'client' },
					{ id: 'c-2', name: 'Buurtvereniging Oost', type: 'organization', clientName: 'Ellen Visser, secretaris', _matchType: 'client' },
					{ id: 'k-1', name: 'Henk de Vries', _matchType: 'contact', clientName: 'Inwoner, Parkstraat 24' },
				],
				rawNumber: '0786123341',
			},
			global: { mocks: { t: globalThis.t } },
		})
		expect(wrapper.find('[data-subtitle]').attributes('data-subtitle')).toBe('Multiple contacts match 078 612 33 41. Select one to open, or create a new contact.')
		const rows = wrapper.findAll('tbody tr').map((row) => row.findAll('td').slice(1, 3).map((cell) => cell.text()))
		expect(rows).toEqual([['Inwoner, Parkstraat 24', 'Person'], ['Ellen Visser, secretaris', 'Organisation'], ['Inwoner, Parkstraat 24', 'Contact person']])
		expect(wrapper.find('[data-testid="screen-pop-new"]').exists()).toBe(true)
	})
})

describe('the screen pop names a matched caller', () => {
	it('shows the preferred name first and the full name below it', () => {
		const wrapper = screenPop([
			{ id: 'c-1', name: 'Johannes Jansen', preferredName: 'Jan' },
		])
		const cell = wrapper.find('tbody tr td')

		expect(cell.find('[data-testid="screen-pop-preferred-name"]').text()).toBe(
			'Jan',
		)
		expect(cell.find('[data-testid="screen-pop-full-name"]').text()).toBe(
			'Johannes Jansen',
		)
		expect(cell.text().indexOf('Jan')).toBeLessThan(
			cell.text().indexOf('Johannes Jansen'),
		)
	})

	it('shows only the full name for a caller without a preferred name', () => {
		const wrapper = screenPop([
			{ id: 'c-2', name: 'Hanna de Wit' },
			{ id: 'c-3', name: 'Kees Kok', preferredName: '   ' },
			{ id: 'c-4', name: 'Sanne', preferredName: 'Sanne' },
		])
		const cells = wrapper.findAll('tbody tr').map((row) => row.find('td'))

		expect(
			wrapper.find('[data-testid="screen-pop-preferred-name"]').exists(),
		).toBe(false)
		expect(cells.map((cell) => cell.text())).toEqual([
			'Hanna de Wit',
			'Kees Kok',
			'Sanne',
		])
	})
})

describe('the detail headers carry the preferred name', () => {
	it.each(['ContactDetail', 'ClientDetail'])(
		'%s lists it as a header field',
		(id) => {
			expect(page(id).config.headerFields).toEqual(['preferredName'])
		},
	)

	it('a client can have it filled in next to the name', () => {
		const identity = page('ClientDetail').config.widgets.find(
			(w) => w.id === 'client-identity',
		)
		expect(identity.content.include.slice(0, 2)).toEqual([
			'name',
			'preferredName',
		])
	})

	it('an organisation shows no preferred name row: only a person has one', () => {
		const identity = page('ClientDetail').config.widgets.find(
			(w) => w.id === 'client-identity',
		)
		// The shared visibility predicate of nextcloud-vue, local mode,
		// read by CnObjectDataWidget on each field of `content.overrides`.
		expect(identity.content.overrides.preferredName.visibleWhen).toEqual({
			field: 'type',
			value: 'person',
		})
	})

	it('the contact details name the fields board PqContactpersoon draws, the preferred name among them', () => {
		const details = page('ContactDetail').config.widgets.find(
			(w) => w.id === 'contact-data',
		)
		expect(details.content.include).toContain('preferredName')
		expect(details.content.exclude ?? []).not.toContain('preferredName')
	})
})
