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
const setResult = vi.fn()
vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: stub('CnSettingsSection', 'section'),
	CnDeleteDialog: {
		name: 'CnDeleteDialog',
		props: ['item', 'nameFormatter', 'dialogTitle'],
		emits: ['confirm', 'close'],
		methods: { setResult },
		render() {
			return h('div', { 'data-dialog': this.dialogTitle })
		},
	},
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
			props: { title: 'Lead Sources', tags, tagLabel: leadSourceLabel, remove: vi.fn() },
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
			props: { title: 'Lead Sources', tags, tagLabel: leadSourceLabel, remove: vi.fn() },
			global: { mocks: { t: globalThis.t } },
		})
		await wrapper.find('.tag-chip__label').trigger('dblclick')
		expect(wrapper.find('.tag-chip__input').element.value).toBe('social_media')
	})

	it('shows names unchanged without a label function', () => {
		const wrapper = mount(TagManager, {
			props: { title: 'Request Channels', tags, remove: vi.fn() },
			global: { mocks: { t: globalThis.t } },
		})
		expect(wrapper.find('.tag-chip__label').text()).toBe('social_media')
	})

	it('asks in a dialog before removing, and shows the server\'s refusal of a value in use', async () => {
		const remove = vi.fn().mockRejectedValue(new Error('Sociale media is still used by 3 leads. Give them another source first.'))
		const wrapper = mount(TagManager, {
			props: { title: 'Lead Sources', tags, tagLabel: leadSourceLabel, remove },
			global: { mocks: { t: globalThis.t } },
		})
		wrapper.vm.confirmRemove(tags[0])
		await flushPromises()
		const dialog = wrapper.findComponent({ name: 'CnDeleteDialog' })
		expect(dialog.attributes('data-dialog')).toBe('Remove Sociale media')
		expect(remove).not.toHaveBeenCalled()

		dialog.vm.$emit('confirm', 1)
		await flushPromises()
		expect(remove).toHaveBeenCalledWith(1)
		expect(setResult).toHaveBeenLastCalledWith({ error: 'Sociale media is still used by 3 leads. Give them another source first.' })
	})

	it('is wired for lead sources on the admin page', () => {
		const settings = readFileSync(
			resolve(__dirname, '../../src/views/settings/Settings.vue'),
			'utf8',
		)
		expect(settings).toContain(':tagLabel="leadSourceLabel"')
		expect(settings).toContain(':remove="removeLeadSource"')
		expect(settings).not.toMatch(/usageCheck|countObjectsWithField/)
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
