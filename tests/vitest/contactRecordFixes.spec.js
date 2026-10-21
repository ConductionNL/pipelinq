// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PL-ABSTRACT bugs 17-19 on the contact record (lane PL1):
 * - the relationship form is a dialog in src/dialogs/ (focus trap, Escape),
 *   not a hand-rolled overlay;
 * - the activity timeline formats dates in the reader's locale, not nl-NL;
 * - the BRP panel draws icons instead of emoji and its messages carry no
 *   em-dash;
 * - the components nothing mounted are gone.
 *
 * @spec openspec/specs/contact-relationship-mapping/spec.md#requirement-relationship-management-on-contact-detail-v1
 */

import { mount } from '@vue/test-utils'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

function stub(name, tag = 'div', props = []) {
	return {
		name,
		inheritAttrs: false,
		props: ['disabled', 'name', 'variant', ...props],
		emits: ['click', 'closing', 'update:modelValue'],
		render() {
			return h(tag, { 'data-testid': this.$attrs['data-testid'], 'data-stub': name, disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcDateTimePickerNative: stub('NcDateTimePickerNative', 'div', ['modelValue', 'label']),
	NcDialog: stub('NcDialog', 'div'),
	NcSelect: stub('NcSelect', 'div', ['modelValue', 'inputLabel', 'options']),
	NcTextArea: stub('NcTextArea', 'div', ['modelValue', 'label']),
}))

const { default: RelationshipFormDialog } = await import('../../src/dialogs/RelationshipFormDialog.vue')

const root = resolve(__dirname, '../..')
const read = (...parts) => readFileSync(resolve(root, ...parts), 'utf8')

describe('RelationshipFormDialog', () => {
	const mountDialog = (form) => mount(RelationshipFormDialog, {
		props: { form, typeOptions: [{ value: 'partner', label: 'Partner' }] },
		global: { mocks: { t: (app, text) => text } },
	})

	it('is a dialog with a labelled select for every choice', () => {
		const wrapper = mountDialog({ toContact: null, type: null, strength: 'medium' })
		expect(wrapper.find('[data-stub="NcDialog"]').exists()).toBe(true)
		for (const select of wrapper.findAllComponents({ name: 'NcSelect' })) {
			expect(select.props('inputLabel')).toBeTruthy()
		}
	})

	it('keeps the save off until an entity and a type are chosen', () => {
		const wrapper = mountDialog({ toContact: null, type: null })
		expect(wrapper.find('[data-testid="relationship-form-save"]').attributes('disabled')).toBeDefined()
	})

	it('hands the parent the fields it collected', async () => {
		const wrapper = mountDialog({ toContact: 'c-2', type: 'partner', notes: 'x', startDate: '2026-01-02', strength: 'strong' })
		await wrapper.find('[data-testid="relationship-form-save"]').trigger('click')
		expect(wrapper.emitted('save')[0][0]).toMatchObject({ toContact: 'c-2', type: 'partner', notes: 'x', startDate: '2026-01-02', strength: 'strong' })
	})

	it('stores a picked date as a local YYYY-MM-DD', () => {
		const wrapper = mountDialog({ toContact: 'c-2', type: 'partner' })
		expect(wrapper.vm.asIso(new Date(2026, 9, 21))).toBe('2026-10-21')
		expect(wrapper.vm.asIso(null)).toBe('')
		expect(wrapper.vm.asDate('2026-10-21').getDate()).toBe(21)
	})

	it('replaces the inline overlay in ContactRelationships', () => {
		const source = read('src', 'components', 'ContactRelationships.vue')
		expect(source).toContain('<RelationshipFormDialog')
		expect(source).not.toContain('create-overlay')
	})
})

describe('the contact record speaks the reader\'s language', () => {
	afterEach(() => {
		delete globalThis.OC
	})

	it('formats timeline dates in the user locale', () => {
		const source = read('src', 'components', 'ActivityTimeline.vue')
		expect(source).not.toContain("'nl-NL'")
		expect(source).toContain('toLocaleString(getUserLocale()')
	})

	it('draws icons, not emoji, in the BRP panel and writes no em-dash to the user', () => {
		const source = read('src', 'components', 'BrpContactPanel.vue')
		const template = source.slice(0, source.indexOf('<script>'))
		expect(template).not.toMatch(/🔒|⚡/u)
		expect(source).not.toMatch(/t\('pipelinq',\s*'[^']*—/u)
		expect(source).not.toMatch(/'pipelinq',\s*\n\s*'[^']*—/u)
	})
})

describe('components nothing mounted are gone', () => {
	it.each([
		'src/components/CallTimer.vue',
		'src/components/CommunicationHistory.vue',
		'src/components/EntityNotes.vue',
		'src/components/LeadContactRoles.vue',
		'src/dialogs/AddContactRoleDialog.vue',
		'src/components/LeadProducts.vue',
		'src/components/ProductRevenue.vue',
		'src/components/ProspectCard.vue',
		'src/components/ProspectWidget.vue',
		'src/components/werkplek/WerkplekNewTaskDialog.vue',
	])('%s', (file) => {
		expect(existsSync(resolve(root, file))).toBe(false)
	})

	it('names the agent status toggle by what it does', () => {
		expect(existsSync(resolve(root, 'src/components/werkplek/AgentAvailabilityToggle.vue'))).toBe(true)
		expect(read('src', 'views', 'werkplek', 'widgets', 'WerkplekHeaderActions.vue')).toContain('AgentAvailabilityToggle')
	})
})
