// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the dashboard's create lead and start request widgets have in common:
 * the manifest title they declare, the state a fresh widget starts in, the
 * picked client, and the form and success panels' layout.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-23
 */
import { parse } from '@vue/compiler-sfc'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: { template: '<button><slot /></button>' }, NcNoteCard: { template: '<div><slot /></div>' }, NcSelect: { template: '<div />' }, NcTextField: { template: '<input>' } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn(async () => ({ objectStore: { objectTypeRegistry: {} } })) }))
vi.mock('../../src/components/widgets/ClientAutocomplete.vue', () => ({ default: { template: '<div />' } }))

globalThis.t = (_app, text) => text

const widgets = {
	'widgets/CreateLeadWidget.vue': (await import('../../src/views/widgets/CreateLeadWidget.vue')).default,
	'widgets/StartRequestWidget.vue': (await import('../../src/views/widgets/StartRequestWidget.vue')).default,
}

/**
 * @param {string} file The widget, from src/views/.
 * @return {Array<{selector: string, body: string}>} Its scoped rules in the order they apply.
 */
function scopedRules(file) {
	const path = resolve(__dirname, '../../src/views', file)
	const { descriptor } = parse(readFileSync(path, 'utf8'))
	const css = descriptor.styles
		.map((style) => (style.src ? readFileSync(resolve(dirname(path), style.src), 'utf8') : style.content))
		.join('\n')
		.replace(/\/\*[\s\S]*?\*\//g, '')
	return [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(([, selector, body]) => ({
		selector: selector.trim().replace(/\s+/g, ' '),
		body: body.trim().split(/\s*;\s*/).filter(Boolean).join('; '),
	}))
}

describe.each(Object.keys(widgets))('%s', (file) => {
	const widget = widgets[file]

	it('declares the manifest title as a required string', () => {
		const props = { ...Object.assign({}, ...(widget.mixins || []).map((m) => m.props || {})), ...widget.props }
		expect(props.title).toEqual({ type: String, required: true })
	})

	it('starts unsubmitted, without a client, and takes the client the user picks', async () => {
		const wrapper = mount(widget, { props: { title: 'Card' }, global: { mocks: { t: globalThis.t } } })
		expect([wrapper.vm.selectedClient, wrapper.vm.submitted, wrapper.vm.submitting, wrapper.vm.success, wrapper.vm.successLink]).toEqual([null, false, false, false, ''])
		wrapper.vm.onClientSelected({ id: 'c1' })
		expect(wrapper.vm.selectedClient).toEqual({ id: 'c1' })
		expect(wrapper.attributes('title')).toBeUndefined()
	})

	it('stacks its form and its success panel with an 8px gap', () => {
		const rules = scopedRules(file)
		for (const selector of ['.widget-form', '.widget-success']) {
			expect(rules.filter((rule) => rule.selector === selector).map((rule) => rule.body)).toEqual(['display: flex; flex-direction: column; gap: 8px'])
		}
	})
})
