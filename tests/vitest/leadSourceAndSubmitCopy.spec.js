// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Copy a Dutch reader saw in English or as a raw tag: the contact moment
 * form's submit label on the werkplek, and the lead source tags in the lead
 * form and the admin lead sources list. Known tags show their label from the
 * shared map; a tag an admin typed shows as typed.
 *
 * @spec openspec/specs/lead-management/spec.md
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => (text === 'Social media' ? 'Sociale media' : text),
}))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name'],
		render() {
			return h(tag, { 'data-stub': name }, [
				this.$slots.actions?.(),
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: stub('CnSettingsSection', 'section'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { leadSourceLabel } = await import('../../src/services/leadSourceLabels.js')
const { default: TagManager } =
	await import('../../src/views/settings/TagManager.vue')

describe('dashboard widget submit labels', () => {
	it('has a Dutch translation for the werkplek submit label', () => {
		const nl = JSON.parse(
			readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
		).translations
		expect(nl['Save contact moment']).toBe('Contactmoment opslaan')
	})
})

describe('admin lead sources list', () => {
	const tags = [
		{ id: 1, name: 'social_media' },
		{ id: 2, name: 'Beurs Utrecht' },
	]

	it('shows a known tag by its label and an admin tag as typed', async () => {
		const wrapper = mount(TagManager, {
			props: { title: 'Lead Sources', tags, tagLabel: leadSourceLabel },
			global: { mocks: { t: globalThis.t } },
		})
		await flushPromises()
		const labels = wrapper
			.findAll('.tag-chip__label')
			.map((label) => label.text())
		expect(labels).toEqual(['Sociale media', 'Beurs Utrecht'])
	})

	it('renames the stored name, not the label', async () => {
		const wrapper = mount(TagManager, {
			props: { title: 'Lead Sources', tags, tagLabel: leadSourceLabel },
			global: { mocks: { t: globalThis.t } },
		})
		await wrapper.find('.tag-chip__label').trigger('dblclick')
		expect(wrapper.find('.tag-chip__input').element.value).toBe('social_media')
	})

	it('shows names unchanged without a label function', () => {
		const wrapper = mount(TagManager, {
			props: { title: 'Request Channels', tags },
			global: { mocks: { t: globalThis.t } },
		})
		expect(wrapper.find('.tag-chip__label').text()).toBe('social_media')
	})

	it('is wired for lead sources on the admin page', () => {
		const settings = readFileSync(
			resolve(__dirname, '../../src/views/settings/Settings.vue'),
			'utf8',
		)
		expect(settings).toContain(':tagLabel="leadSourceLabel"')
	})
})

describe('lead form source select', () => {
	it('offers each source with its label and stores the tag', () => {
		const form = readFileSync(
			resolve(__dirname, '../../src/views/leads/LeadForm.vue'),
			'utf8',
		)
		expect(form).toMatch(/:reduce="\(o\) => o\.id"/)
		expect(form).toContain('label: leadSourceLabel(id)')
	})
})
