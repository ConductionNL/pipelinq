// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The marketing tab of the admin page as the PqBeheerMarketing board draws it:
 * the market insight card opens on its fields with its own save label and
 * Dutch title, the signup form can be copied, and the Dutch words of the mail
 * routes table follow the board.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const ROOT = resolve(__dirname, '../..')
const NL = JSON.parse(readFileSync(resolve(ROOT, 'l10n/nl.json'), 'utf8')).translations
const sections = JSON.parse(
	readFileSync(resolve(ROOT, 'src/views/settings/adminSections.json'), 'utf8'),
)

vi.mock('../../src/services/mailingListApi.js', () => ({
	fetchMailingLists: vi.fn(async () => [
		{ id: 'l1', name: 'Nieuwsbrief bewoners', publicSignup: true },
	]),
}))
vi.mock('../../src/services/subscriptionState.js', () => ({
	embedSnippet: () => '<form action="/subscribe"></form>',
}))

/**
 * A stub that renders its default slot.
 *
 * @param {string} name The component name.
 * @param {string} tag The element.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'type', 'name'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				tag,
				{ 'data-stub': name, class: this.$attrs.class, onClick: () => this.$emit('click') },
				[this.$slots.default?.()],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

globalThis.t = (app, text) => text

const { default: MailingListEmbedSettings } =
	await import('../../src/views/settings/MailingListEmbedSettings.vue')

describe('the marketing tab as the board', () => {
	it('opens the market insight card on its fields, without a keywords heading', () => {
		const keys = sections.marketingIntel.fields.map((field) => field.key)
		expect(keys[0]).toBe('search.crawl_source')
		expect(keys).not.toContain('keywordsHeading')
		expect(sections.marketingIntel.saveLabel).toBe('Save market insight settings')
	})

	it('names the market insight card and its save in Dutch as the board', () => {
		expect(NL['Marketing intelligence']).toBe('Marktinzicht')
		expect(NL['Save market insight settings']).toBe('Instellingen voor marktinzicht opslaan')
		expect(NL['Make default']).toBe('Standaard maken')
		expect(NL.Deliverability).toBe('Afleverbaarheid')
	})

	it('copies the signup form with a tonal Copy button', async () => {
		const writeText = vi.fn(async () => {})
		Object.assign(navigator, { clipboard: { writeText } })
		const wrapper = mount(MailingListEmbedSettings, {
			global: { mocks: { t: globalThis.t } },
		})
		await flushPromises()
		wrapper.vm.selected = { id: 'l1', label: 'Nieuwsbrief bewoners', publicSignup: true }
		await flushPromises()

		const copy = wrapper.get('button.cn-button--tonal')
		expect(copy.text()).toBe('Copy')
		await copy.trigger('click')
		expect(writeText).toHaveBeenCalledWith('<form action="/subscribe"></form>')
	})
})
