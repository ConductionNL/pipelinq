// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the agent profile and skill sections on the admin page show and store:
 * a profile row names its skills from the catalogue and its capacity, an
 * unavailable agent is marked, ticking a skill adds or drops it once, a new
 * profile takes the prompted user id; a skill row lists its categories or
 * says it has none, an inactive skill is marked, and saving splits the typed
 * categories into a clean list.
 *
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-settings-ui/tasks.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label'], render() { return h('span', { class: 'badge' }, this.label) } },
}))

const skills = vi.hoisted(() => ({}))
const profiles = vi.hoisted(() => ({}))
vi.mock('../../src/store/modules/skills.js', () => ({ useSkillsStore: () => skills }))
vi.mock('../../src/store/modules/agentProfiles.js', () => ({ useAgentProfilesStore: () => profiles }))

globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: SkillSettings } = await import('../../src/components/admin/SkillSettings.vue')
const { default: AgentProfileSettings } = await import('../../src/components/admin/AgentProfileSettings.vue')

beforeEach(() => {
	Object.assign(skills, {
		skills: [
			{ id: 's1', title: 'Parking', categories: ['permits', 'fines'] },
			{ id: 's2', title: 'Waste', categories: [], isActive: false },
		],
		loading: false,
		fetchSkills: vi.fn(),
		saveSkill: vi.fn(async () => true),
		deleteSkill: vi.fn(),
	})
	Object.assign(profiles, {
		profiles: [
			{ id: 'p1', userId: 'anna', skills: ['s2', 'gone', 's1'], maxConcurrent: 3 },
			{ id: 'p2', userId: 'bert', skills: [], isAvailable: false },
		],
		loading: false,
		fetchProfiles: vi.fn(),
		saveProfile: vi.fn(async () => true),
		deleteProfile: vi.fn(),
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * Mount a section with the test translator.
 *
 * @param {object} component The section component.
 * @return {object} The wrapper.
 */
function mountSection(component) {
	return mount(component, { global: { mocks: { t: globalThis.t } } })
}

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

describe('an agent profile row', () => {
	it('loads profiles and skills when it mounts', () => {
		mountSection(AgentProfileSettings)
		expect(profiles.fetchProfiles).toHaveBeenCalledTimes(1)
		expect(skills.fetchSkills).toHaveBeenCalledTimes(1)
	})

	it('names its skills in its own order, skipping ones the catalogue no longer has, and its capacity', () => {
		const rows = mountSection(AgentProfileSettings).findAll('.agent-item')
		expect(rows[0].find('.agent-meta').text().replace(/\s+/g, ' ')).toBe('Waste, Parking · max 3 items')
		expect(rows[1].find('.agent-meta').text().replace(/\s+/g, ' ')).toBe('No skills · max 10 items')
	})

	it('says "No skills" while the skill catalogue is empty', () => {
		skills.skills = []
		const row = mountSection(AgentProfileSettings).find('.agent-item')
		expect(row.find('.agent-meta').text()).toMatch(/^No skills/)
	})

	it('marks only an agent that is explicitly unavailable', () => {
		const rows = mountSection(AgentProfileSettings).findAll('.agent-item')
		expect(rows[0].find('.badge').exists()).toBe(false)
		expect(rows[1].find('.badge').text()).toBe('Unavailable')
	})

	it('shows the spinner instead of the rows while loading', () => {
		profiles.loading = true
		const wrapper = mountSection(AgentProfileSettings)
		expect(wrapper.find('[data-stub="NcLoadingIcon"]').exists()).toBe(true)
		expect(wrapper.findAll('.agent-item')).toHaveLength(0)
	})

	it('adds a ticked skill once, drops an unticked one, and saves the edited profile', async () => {
		const wrapper = mountSection(AgentProfileSettings)
		await press(wrapper, 'Edit')
		const boxes = wrapper.findAll('.skill-checkbox input')
		expect(boxes.map((box) => box.element.checked)).toEqual([true, true])

		await boxes[0].setValue(false)
		expect(wrapper.vm.editForm.skills).toEqual(['s2', 'gone'])
		await boxes[0].setValue(true)
		expect(wrapper.vm.editForm.skills).toEqual(['s2', 'gone', 's1'])
		wrapper.vm.toggleSkill('s1', true)
		expect(wrapper.vm.editForm.skills).toEqual(['s2', 'gone', 's1'])
		wrapper.vm.toggleSkill('never-there', false)
		expect(wrapper.vm.editForm.skills).toEqual(['s2', 'gone', 's1'])

		await wrapper.find('#agent-edit-max-concurrent').setValue('7')
		await press(wrapper, 'Save')
		expect(profiles.saveProfile).toHaveBeenCalledWith({ id: 'p1', userId: 'anna', skills: ['s2', 'gone', 's1'], maxConcurrent: 7 })
		expect(profiles.profiles[0].skills).toEqual(['s2', 'gone', 's1'])
	})

	it('starts a skill list for a profile that had none', async () => {
		const wrapper = mountSection(AgentProfileSettings)
		wrapper.vm.startEdit({ id: 'p3', userId: 'cees' })
		wrapper.vm.toggleSkill('s1', true)
		expect(wrapper.vm.editForm.skills).toEqual(['s1'])
	})

	it('adds a profile for the prompted user id, with default routing', async () => {
		vi.stubGlobal('prompt', vi.fn(() => 'dirk'))
		const wrapper = mountSection(AgentProfileSettings)
		await press(wrapper, 'Add agent profile')
		expect(globalThis.prompt).toHaveBeenCalledWith('Enter Nextcloud user ID:')
		expect(profiles.saveProfile).toHaveBeenCalledWith({ userId: 'dirk', skills: [], maxConcurrent: 10, isAvailable: true })
	})

	it('adds nothing when the prompt is cancelled or left empty', async () => {
		vi.stubGlobal('prompt', vi.fn().mockReturnValueOnce(null).mockReturnValueOnce(''))
		const wrapper = mountSection(AgentProfileSettings)
		await press(wrapper, 'Add agent profile')
		await press(wrapper, 'Add agent profile')
		expect(profiles.saveProfile).not.toHaveBeenCalled()
	})
})

describe('a skill row', () => {
	it('loads the skills when it mounts', () => {
		mountSection(SkillSettings)
		expect(skills.fetchSkills).toHaveBeenCalledTimes(1)
	})

	it('lists its categories, or says it has none, and marks only an inactive skill', () => {
		const rows = mountSection(SkillSettings).findAll('.skill-item')
		expect(rows[0].find('.skill-meta').text()).toBe('permits, fines')
		expect(rows[0].find('.inactive-tag').exists()).toBe(false)
		expect(rows[1].find('.skill-meta').text()).toBe('No categories')
		expect(rows[1].find('.inactive-tag').text()).toBe('Inactive')
	})

	it('saves the typed categories trimmed, without blanks and without the input field', async () => {
		const wrapper = mountSection(SkillSettings)
		await press(wrapper, 'Edit')
		await wrapper.find('#skill-edit-categories').setValue(' permits ,, towing , ')
		await wrapper.find('#skill-edit-title').setValue('Parking & towing')
		await press(wrapper, 'Save')
		expect(skills.saveSkill).toHaveBeenCalledWith({ id: 's1', title: 'Parking & towing', categories: ['permits', 'towing'] })
		expect(wrapper.vm.editingId).toBe(null)
	})

	it('saves an empty category list when the field is cleared', async () => {
		const wrapper = mountSection(SkillSettings)
		await press(wrapper, 'Edit')
		await wrapper.find('#skill-edit-categories').setValue('')
		await press(wrapper, 'Save')
		expect(skills.saveSkill).toHaveBeenCalledWith({ id: 's1', title: 'Parking', categories: [] })
	})

	it('adds an active, uncategorised "New Skill"', async () => {
		const wrapper = mountSection(SkillSettings)
		await press(wrapper, 'Add skill')
		expect(skills.saveSkill).toHaveBeenCalledWith({ title: 'New Skill', isActive: true, categories: [] })
	})
})
