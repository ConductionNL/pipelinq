// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqOrganisatiesVerrijken: selected organisations are looked up in the
 * KvK; an empty field's proposal starts ticked, a different value unticked,
 * and only the ticked ones are written. The Clients list offers it as a bulk
 * action.
 *
 * @spec openspec/changes/organisation-enrichment/specs/client-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnStatusBadge: { props: ['label'], render() { return h('span', { class: 'pill' }, this.label) } },
}))
vi.mock('@nextcloud/vue', () => {
	const tag = (name) => ({ props: ['disabled', 'variant'], emits: ['click'], render() { return h(name, { onClick: () => this.$emit('click') }, this.$slots.default?.()) } })
	return { NcButton: tag('button'), NcLoadingIcon: tag('span'), NcNoteCard: tag('div') }
})

let Dialog
beforeAll(async () => {
	Dialog = (await import('../../src/dialogs/OrganisationEnrichDialog.vue')).default
})

const PROPOSALS = {
	proposals: [
		{ clientId: 'c1', name: 'Bakkerij De Molen', field: 'kvkNumber', current: '', found: '00001008', source: 'KVK', differs: false },
		{ clientId: 'c2', name: 'Dagblad Drechtsteden B.V.', field: 'place', current: 'Dordrecht', found: 'Zuiddrecht', source: 'KVK', differs: true },
	],
	notFound: ['Scoutinggroep Zuiddrecht'],
}

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
	http.post.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/proposals') ? PROPOSALS : { updated: 1 } }))
})

const fill = (text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
const global = { mixins: [{ methods: { t: (app, text, vars) => fill(text, vars), n: (app, one, many, count, vars) => fill(count === 1 ? one : many, vars).replace('%n', count) } }] }

describe('OrganisationEnrichDialog', () => {
	it('ticks empty fields, leaves differences unticked and names what was not found', async () => {
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1', 'c2', 'c3'] }, global })
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/organisations/enrich/proposals', { ids: ['c1', 'c2', 'c3'] })
		expect(wrapper.vm.accepted).toEqual([true, false])
		expect(wrapper.find('[data-testid="enrich-summary"]').text()).toContain('1 not found: Scoutinggroep Zuiddrecht')
		expect(wrapper.find('[data-testid="enrich-apply"]').text()).toBe('Fill in 1 field')
	})

	it('writes only the ticked proposals and refreshes the list', async () => {
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1', 'c2'] }, global })
		await flushPromises()
		await wrapper.find('[data-testid="enrich-apply"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/organisations/enrich/apply', { changes: [{ clientId: 'c1', field: 'kvkNumber', value: '00001008' }] })
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toBeTruthy()
	})

	it('is a bulk action on the Clients list', () => {
		const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const clients = manifest.pages.find((p) => p.id === 'Clients')
		expect(clients.config.bulkActions).toContainEqual(expect.objectContaining({ id: 'enrich', handler: 'open-modal', target: 'OrganisationEnrichDialog' }))
	})
})
