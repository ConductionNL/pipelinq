// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqKlant's Contactmoment registreren card: an empty Subject does not say
 * "Onderwerp is verplicht" before the agent has left the field, and the
 * paired fields share the card's width in two columns instead of running
 * past its edge. As drawn on PqKlant and PqOrganisatie, Summary and Notes
 * are three-row text areas and Cancel is the outlined button beside Save at
 * the card's end.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-22
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => ({ fetchCollection: vi.fn(async () => []), saveObject: vi.fn(), deleteObject: vi.fn(), getError: vi.fn() }),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (u) => u }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnResourceSelect: { template: '<div />' } }))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['variant'], template: '<button class="btn" :data-variant="variant"><slot /></button>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcSelect: { template: '<div />' },
	NcTextArea: {
		props: ['label', 'modelValue', 'rows', 'resize'],
		template: '<label class="area" :data-label="label" :data-rows="rows" :data-resize="resize"><textarea /></label>',
	},
	NcTextField: {
		props: ['label', 'helperText', 'error', 'modelValue'],
		emits: ['blur', 'update:modelValue'],
		template: '<label class="field" :data-label="label" :data-error="String(!!error)"><input @blur="$emit(\'blur\')" /><span class="helper">{{ helperText }}</span></label>',
	},
}))

globalThis.t = (_app, text) => text
window.OC = { getCurrentUser: () => null }
const { default: QuickLog } = await import('../../src/components/ContactmomentQuickLog.vue')

/**
 * @return {Promise<object>} The mounted form.
 */
async function mountForm() {
	const wrapper = mount(QuickLog, { props: { inline: true }, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

const subject = (wrapper) => wrapper.find('.field[data-label="Subject"]')

describe('the contact moment quick log', () => {
	it('says nothing about an empty subject before the field is left', async () => {
		const wrapper = await mountForm()
		expect(subject(wrapper).find('.helper').text()).toBe('')
		expect(subject(wrapper).attributes('data-error')).toBe('false')
	})

	it('says the subject is required once the empty field is left', async () => {
		const wrapper = await mountForm()
		await subject(wrapper).find('input').trigger('blur')
		expect(subject(wrapper).find('.helper').text()).toBe('Subject is required')
		expect(subject(wrapper).attributes('data-error')).toBe('true')
	})

	it('lays paired fields in two columns that shrink with the card', () => {
		const css = readFileSync(resolve(__dirname, '../../src/components/ContactmomentQuickLog.vue'), 'utf8').split('<style')[1]
		const row = css.slice(css.indexOf('.form-row {'), css.indexOf('}', css.indexOf('.form-row {')))
		expect(row).toMatch(/grid-template-columns: minmax\(0, 1fr\) minmax\(0, 1fr\);/)
		expect(css).toMatch(/min-width: 0;/)
	})

	it('writes the summary and the notes in three-row text areas', async () => {
		const wrapper = await mountForm()
		for (const label of ['Summary', 'Notes']) {
			const area = wrapper.find(`.area[data-label="${label}"]`)
			expect(area.exists()).toBe(true)
			expect(area.attributes('data-rows')).toBe('3')
			expect(area.attributes('data-resize')).toBe('vertical')
			expect(wrapper.find(`.field[data-label="${label}"]`).exists()).toBe(false)
		}
	})

	it('draws Cancel outlined beside Save at the end of the card', async () => {
		const wrapper = await mountForm()
		const buttons = wrapper.find('.form-actions').findAll('.btn')
		expect(buttons.map((b) => [b.text(), b.attributes('data-variant')])).toEqual([
			['Cancel', 'secondary'],
			['Save', 'primary'],
		])
		const css = readFileSync(resolve(__dirname, '../../src/components/ContactmomentQuickLog.vue'), 'utf8').split('<style')[1]
		const actions = css.slice(css.indexOf('.form-actions {'), css.indexOf('}', css.indexOf('.form-actions {')))
		expect(actions).toMatch(/justify-content: flex-end;/)
	})
})
