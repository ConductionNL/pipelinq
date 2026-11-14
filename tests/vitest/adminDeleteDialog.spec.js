// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Deleting a skill or an agent profile on the admin page asks in
 * CnDeleteDialog, not in the browser's confirm(), and deletes only on its
 * confirm.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-delete-on-the-admin-page-asks-in-a-dialog
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'variant'],
		emits: ['click'],
		render() {
			return h(tag, { 'data-stub': name, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	}
}

const setResult = vi.fn()
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDeleteDialog: {
		name: 'CnDeleteDialog',
		props: ['item', 'nameField', 'dialogTitle'],
		emits: ['confirm', 'close'],
		methods: { setResult },
		render() {
			return h('div', { 'data-dialog': this.item[this.nameField] })
		},
	},
	CnStatusBadge: {
		name: 'CnStatusBadge',
		props: ['label', 'variant'],
		render() {
			return h('span', this.label)
		},
	},
}))

const skills = { skills: [{ id: 's1', title: 'Parking', categories: [] }], loading: false, fetchSkills: vi.fn(), deleteSkill: vi.fn(async () => true), error: null }
const profiles = { profiles: [{ id: 'p1', userId: 'anna', skills: [] }], loading: false, fetchProfiles: vi.fn(), deleteProfile: vi.fn(async () => false), error: 'Not allowed' }
vi.mock('../../src/store/modules/skills.js', () => ({ useSkillsStore: () => skills }))
vi.mock('../../src/store/modules/agentProfiles.js', () => ({ useAgentProfilesStore: () => profiles }))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: SkillSettings } = await import('../../src/components/admin/SkillSettings.vue')
const { default: AgentProfileSettings } = await import('../../src/components/admin/AgentProfileSettings.vue')

/**
 * Press the row's Delete button.
 *
 * @param {object} wrapper The mounted section.
 * @return {Promise<void>}
 */
async function pressDelete(wrapper) {
	const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Delete')
	await button.trigger('click')
	await flushPromises()
}

describe('deleting on the admin page', () => {
	it('asks in a dialog before deleting a skill, and deletes on its confirm', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = mount(SkillSettings, { global: { mocks: { t: globalThis.t } } })
		await pressDelete(wrapper)
		expect(window.confirm).not.toHaveBeenCalled()
		expect(skills.deleteSkill).not.toHaveBeenCalled()
		const dialog = wrapper.findComponent({ name: 'CnDeleteDialog' })
		expect(dialog.attributes('data-dialog')).toBe('Parking')

		dialog.vm.$emit('confirm', 's1')
		await flushPromises()
		expect(skills.deleteSkill).toHaveBeenCalledWith('s1')
		expect(setResult).toHaveBeenLastCalledWith({ success: true })
	})

	it('says in the dialog why an agent profile was not deleted', async () => {
		const wrapper = mount(AgentProfileSettings, { global: { mocks: { t: globalThis.t } } })
		await pressDelete(wrapper)
		const dialog = wrapper.findComponent({ name: 'CnDeleteDialog' })
		expect(dialog.attributes('data-dialog')).toBe('anna')
		dialog.vm.$emit('confirm', 'p1')
		await flushPromises()
		expect(profiles.deleteProfile).toHaveBeenCalledWith('p1')
		expect(setResult).toHaveBeenLastCalledWith({ error: 'Not allowed' })
	})

	it('has no browser confirm left in either section', () => {
		for (const file of ['SkillSettings.vue', 'AgentProfileSettings.vue']) {
			const source = readFileSync(resolve(__dirname, '../../src/components/admin', file), 'utf8')
			expect(source, file).not.toMatch(/\bconfirm\(/)
		}
	})
})
