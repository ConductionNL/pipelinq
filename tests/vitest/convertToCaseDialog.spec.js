// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Convert to case" asks first, in a dialog (board PqTicketOmzetten): the
 * section's button opens the dialog and posts nothing; the dialog names the
 * ticket and what goes with the case, and only its confirm button converts.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => url.replace('{id}', params?.id) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		props: ['name', 'eyebrow', 'subtitle', 'width'],
		render() {
			return h('div', { class: 'cn-dialog', 'data-eyebrow': this.eyebrow, 'data-subtitle': this.subtitle }, [this.name, this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	CnNoteCard: { props: ['text'], render() { return h('p', this.text) } },
}))
vi.mock('@nextcloud/vue', () => {
	const button = {
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, [this.$slots.default?.()])
		},
	}
	return { NcButton: button, NcLoadingIcon: { render: () => h('span') }, NcNoteCard: { render() { return h('div', this.$slots.default?.()) } } }
})

let Dialog
let Section

beforeAll(async () => {
	// The section uses Nextcloud's global t(), as it does in the browser.
	globalThis.t = (app, text) => text
	Dialog = (await import('../../src/dialogs/ConvertToCaseDialog.vue')).default
	Section = (await import('../../src/views/requests/RequestConversionSection.vue')).default
})

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	store.fetchObject.mockReset()
})

const global = { mixins: [{ methods: { t: (app, text) => text } }] }
const ticket = { reference: 'PQ-2026-0417', title: 'Vraag over vergunning straatfeest Parkstraat', description: 'Een straatfeest.', client: 'c1' }

describe('ConvertToCaseDialog', () => {
	it('names the ticket and what goes with the case', () => {
		const w = mount(Dialog, { props: { ticket, requester: 'Sanne de Vries' }, global })
		const dialog = w.find('.cn-dialog')
		expect(dialog.attributes('data-eyebrow')).toBe('Convert · PQ-2026-0417')
		expect(dialog.attributes('data-subtitle')).toBe('PQ-2026-0417 · Vraag over vergunning straatfeest Parkstraat')
		expect(w.text()).toContain('Sanne de Vries')
		expect(w.text()).toContain('Create case in dossiq')
	})

	it('emits confirm only from its confirm button', async () => {
		const w = mount(Dialog, { props: { ticket }, global })
		await w.find('[data-testid="convert-to-case-cancel"]').trigger('click')
		expect(w.emitted('confirm')).toBeUndefined()
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		expect(w.emitted('confirm')).toHaveLength(1)
	})
})

describe('RequestConversionSection', () => {
	it('opens the dialog and posts nothing until it is confirmed', async () => {
		http.get.mockResolvedValue({ data: { available: true, status: 'in_progress', canConvert: true, caseReference: '' } })
		http.post.mockResolvedValue({ data: { caseReference: 'case-1' } })
		store.fetchObject.mockImplementation((type) => Promise.resolve(type === 'ticket' ? ticket : { name: 'Sanne de Vries' }))
		const w = mount(Section, { props: { requestId: 't1' }, global })
		await flushPromises()
		await w.find('button').trigger('click')
		await flushPromises()
		expect(http.post).not.toHaveBeenCalled()
		expect(w.find('[data-testid="convert-to-case-dialog"]').exists() || w.find('.cn-dialog').exists()).toBe(true)
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/handoff/request/t1/convert-to-case', {})
	})
})
