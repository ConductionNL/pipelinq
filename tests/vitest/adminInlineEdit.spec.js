// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Editing a skill or an agent profile in place on the admin page: Edit opens
 * the row's form with a copy of the row, Cancel closes it and drops the copy,
 * Save stores it and closes it, and Delete asks about that row.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-23
 */
import { flushPromises, mount } from '@vue/test-utils'
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

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDeleteDialog: { name: 'CnDeleteDialog', props: ['item', 'nameField'], render: () => h('div') },
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label'], render() { return h('span', this.label) } },
}))

const skills = { skills: [{ id: 's1', title: 'Parking', categories: ['a', 'b'] }], loading: false, fetchSkills: vi.fn(), saveSkill: vi.fn(async () => true), deleteSkill: vi.fn() }
const profiles = { profiles: [{ id: 'p1', userId: 'anna', skills: [], maxConcurrent: 3 }], loading: false, fetchProfiles: vi.fn(), saveProfile: vi.fn(async () => true), deleteProfile: vi.fn() }
vi.mock('../../src/store/modules/skills.js', () => ({ useSkillsStore: () => skills }))
vi.mock('../../src/store/modules/agentProfiles.js', () => ({ useAgentProfilesStore: () => profiles }))

globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: SkillSettings } = await import('../../src/components/admin/SkillSettings.vue')
const { default: AgentProfileSettings } = await import('../../src/components/admin/AgentProfileSettings.vue')

/**
 * Press the first button with this label.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} label The button's text.
 * @return {Promise<void>}
 */
async function press(wrapper, label) {
	await wrapper.findAll('button').find((button) => button.text() === label).trigger('click')
	await flushPromises()
}

describe.each([
	['skill', SkillSettings, 's1', { id: 's1', title: 'Parking', categories: ['a', 'b'], categoriesInput: 'a, b' }],
	['agent profile', AgentProfileSettings, 'p1', { id: 'p1', userId: 'anna', skills: [], maxConcurrent: 3 }],
])('a %s row', (_kind, component, id, copy) => {
	it('starts empty, opens on Edit with a copy of the row, and closes on Cancel', async () => {
		const wrapper = mount(component, { global: { mocks: { t: globalThis.t } } })
		expect([wrapper.vm.editingId, wrapper.vm.editForm, wrapper.vm.deleting]).toEqual([null, {}, null])
		await press(wrapper, 'Edit')
		expect([wrapper.vm.editingId, wrapper.vm.editForm]).toEqual([id, copy])
		await press(wrapper, 'Cancel')
		expect([wrapper.vm.editingId, wrapper.vm.editForm]).toEqual([null, {}])
	})

	it('closes the form once Save has stored it', async () => {
		const wrapper = mount(component, { global: { mocks: { t: globalThis.t } } })
		await press(wrapper, 'Edit')
		await press(wrapper, 'Save')
		expect([wrapper.vm.editingId, wrapper.vm.editForm]).toEqual([null, {}])
	})

	it('asks about the row on Delete', async () => {
		const wrapper = mount(component, { global: { mocks: { t: globalThis.t } } })
		await press(wrapper, 'Delete')
		expect(wrapper.vm.deleting.id).toBe(id)
	})
})
