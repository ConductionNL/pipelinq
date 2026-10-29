// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqGesprekNieuwContact: every intake field has a visible label above
 * it, tied to its input, with "(optional)" in a lighter weight; the dialog
 * takes the app's look although the incoming-call card renders beside
 * CnAppRoot, so it opens at the board's form width.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.2
 */

import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h, unref } from 'vue'

vi.mock('@nextcloud/vue', () => {
	const field = (name, tag) => ({
		name,
		inheritAttrs: false,
		props: ['id', 'label', 'labelOutside', 'modelValue', 'placeholder'],
		render() {
			return h(tag, { id: this.id, placeholder: this.placeholder, 'aria-label': this.labelOutside ? undefined : this.label })
		},
	})
	return {
		NcButton: { name: 'NcButton', render() { return h('button', this.$slots.default?.()) } },
		NcTextArea: field('NcTextArea', 'textarea'),
		NcTextField: field('NcTextField', 'input'),
	}
})

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'subtitle', 'width'],
		render() {
			return h('div', { 'data-width': this.width }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	CnNoteCard: { name: 'CnNoteCard', props: ['text', 'type'], render() { return h('div', this.text) } },
}))

let Modal

const mountModal = () => mount(Modal, {
	props: { prefillPhone: '0641228790' },
	global: { mixins: [{ methods: { t: (_app, text, vars) => globalThis.t(_app, text, vars) } }] },
})

beforeAll(async () => {
	globalThis.t = (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)
	Modal = (await import('../../src/modals/NewContactIntakeModal.vue')).default
})

describe('NewContactIntakeModal labels', () => {
	it('ties a visible label to every field', () => {
		const w = mountModal()
		const controls = w.findAll('input, textarea')
		expect(controls).toHaveLength(5)
		for (const control of controls) {
			const id = control.attributes('id')
			expect(id).toBeTruthy()
			const label = w.find(`label[for="${id}"]`)
			expect(label.exists()).toBe(true)
			expect(label.text().length).toBeGreaterThan(0)
		}
	})

	it('names the optional fields optional in a lighter span', () => {
		const w = mountModal()
		expect(w.find('label[for="cti-intake-name"]').text()).toBe('Name')
		expect(w.find('label[for="cti-intake-organisation"] .cti-intake__optional').text()).toBe('(optional)')
		expect(w.find('#cti-intake-organisation').attributes('placeholder')).toBe('Company or organisation')
	})

	it('asks for the board form width', () => {
		const w = mountModal()
		expect(w.find('[data-width]').attributes('data-width')).toBe('form')
	})
})

describe('App look for the incoming-call card', () => {
	const app = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')

	it('renders the card beside CnAppRoot, so App itself provides the look', () => {
		expect(app.indexOf('<IncomingCallCard />')).toBeGreaterThan(app.indexOf('</CnAppRoot>'))
		expect(app).toMatch(/cnLook: computed\(/)
	})

	it('provides the manifest look', () => {
		const match = app.match(/cnLook: computed\(\(\) => (.*)\),\n/)
		expect(match).not.toBeNull()
		const pick = new Function('manifest', `return ${match[1].replaceAll('this.manifest', 'manifest')}`)
		expect(unref(pick({ look: 'board' }))).toBe('board')
		expect(unref(pick({}))).toBe('nextcloud')
	})
})
