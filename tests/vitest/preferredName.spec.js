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

globalThis.t = (_app, text) => text

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

	it('the contact details show every field, so the preferred name is among them', () => {
		const details = page('ContactDetail').config.widgets.find(
			(w) => w.id === 'contact-data',
		)
		expect(details.content.include).toBeUndefined()
		expect(details.content.exclude ?? []).not.toContain('preferredName')
	})
})
