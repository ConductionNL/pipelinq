// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail transports section mounted: its loading, empty and table states,
 * the deliverability badges per row, and what Make default, Active and
 * Check now write.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({ fetchCollection: vi.fn(), saveObject: vi.fn() }))
const http = vi.hoisted(() => ({ post: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => vars[k]) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('vue-material-design-icons/Refresh.vue', () => ({ default: { name: 'Refresh', render: () => null } }))

/**
 * A stub that renders its slots.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element to render.
 * @return {object} The component.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'name', 'description', 'disabled', 'type'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, { 'data-stub': name, onClick: () => this.$emit('click') }, [
				this.description ? h('p', this.description) : null,
				this.$slots.actions?.(),
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', async () => ({
	CnDataTable: (await import('./support/cnDataTableStub.js')).CnDataTableStub,
	CnSettingsSection: stub('CnSettingsSection', 'section'),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
}))

const { default: DeliverabilitySettings } = await import('../../src/views/settings/DeliverabilitySettings.vue')
const mountOptions = { global: { mocks: { t: globalThis.t } } }

function transports () {
  return [
	{ id: 'a', displayName: 'Instance', kind: 'instance', senderDomain: 'example.org', dkimVerified: true, dmarcStatus: 'found', default: true, active: true },
	{ id: 'b', displayName: 'Bulk', kind: 'provider', senderDomain: 'mail.example.org', dkimVerified: false, dmarcStatus: 'missing', default: false, active: false },
	{ id: 'c', displayName: 'Box', kind: 'mailAccount', default: false, active: true },
]
}

/**
 * Mount the section over the given transports.
 *
 * @param {Array<object>} rows The stored transports.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(rows = transports()) {
	store.fetchCollection.mockResolvedValue(rows)
	const wrapper = mount(DeliverabilitySettings, mountOptions)
	await flushPromises()
	return wrapper
}

function button(wrapper, text, row = null) {
	const scope = row === null ? wrapper : wrapper.findAll('tbody tr')[row]
	return scope.findAll('button').find((b) => b.text() === text)
}

beforeEach(() => {
	vi.resetAllMocks()
	store.saveObject.mockResolvedValue({})
})

describe('DeliverabilitySettings', () => {
	it('says no transport is configured on an empty list', async () => {
		const wrapper = await mounted([])
		expect(store.fetchCollection).toHaveBeenCalledWith('mailTransport', { _limit: 200 })
		expect(wrapper.find('[data-stub="NcEmptyContent"]').text()).toBe('No mail transports configured yet.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('reports a list that cannot load and shows it empty', async () => {
		store.fetchCollection.mockRejectedValue(new Error('down'))
		const wrapper = mount(DeliverabilitySettings, mountOptions)
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('Failed to load mail transports.')
		expect(wrapper.vm.transports).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('shows each transport with its kind and deliverability verdict', async () => {
		const wrapper = await mounted()
		const rows = wrapper.findAll('tbody tr')
		expect(rows.map((r) => r.findAll('td')[1].text())).toEqual(['Instance mail server', 'Bulk provider', 'Mail account'])
		const first = rows[0].findAll('.cn-status-badge')
		expect(first.map((b) => [b.text(), b.classes().slice(1)])).toEqual([
			['DKIM found', ['cn-status-badge--success']],
			['DMARC found', ['cn-status-badge--success']],
			['Default', ['cn-status-badge--primary']],
		])
		const second = rows[1].findAll('.cn-status-badge')
		expect(second.map((b) => b.text())).toEqual(['DKIM missing', 'DMARC missing: bulk senders to Gmail are rejected without it'])
		expect(second[0].classes()).toContain('cn-status-badge--error')
		expect(rows[2].find('.cn-status-badge').text()).toBe('No sender domain')
		expect(wrapper.vm.tableRows[2]).toMatchObject({ senderDomain: '—', deliverability: 'No sender domain' })
		expect(wrapper.vm.tableRows[0].deliverability).toBe('DKIM found, DMARC found')
	})

	it('moves the default: clears the old one, sets the new one, reloads', async () => {
		const wrapper = await mounted()
		await button(wrapper, 'Make default', 1).trigger('click')
		await flushPromises()
		expect(store.saveObject.mock.calls).toEqual([
			['mailTransport', expect.objectContaining({ id: 'a', default: false })],
			['mailTransport', expect.objectContaining({ id: 'b', default: true })],
		])
		expect(toasts.showSuccess).toHaveBeenCalledWith('Default transport updated.')
		expect(store.fetchCollection).toHaveBeenCalledTimes(2)
	})

	it('reports a default that cannot be set', async () => {
		const wrapper = await mounted()
		store.saveObject.mockRejectedValueOnce(new Error(''))
		await button(wrapper, 'Make default', 2).trigger('click')
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('Failed to set the default transport.')
		expect(toasts.showSuccess).not.toHaveBeenCalled()
	})

	it('switches a transport on or off', async () => {
		const wrapper = await mounted()
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[1].vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(store.saveObject).toHaveBeenCalledWith('mailTransport', expect.objectContaining({ id: 'b', active: true }))
		expect(toasts.showSuccess).toHaveBeenCalledWith('Transport updated.')
		store.saveObject.mockRejectedValueOnce(new Error('Locked'))
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })[0].vm.$emit('update:modelValue', false)
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('Locked')
	})

	it('checks a transport now and shows the new verdict in its row', async () => {
		http.post.mockResolvedValueOnce({ data: { dkimVerified: true, dmarcStatus: 'invalid', checkedAt: '2026-11-12' } })
		const wrapper = await mounted()
		await button(wrapper, 'Check now', 1).trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/mail-transports/b/check-deliverability', { refresh: true })
		expect(wrapper.vm.transports[1]).toMatchObject({ dkimVerified: true, dmarcStatus: 'invalid', deliverabilityCheckedAt: '2026-11-12' })
		expect(wrapper.findAll('tbody tr')[1].findAll('.cn-status-badge').map((b) => b.text())).toEqual(['DKIM found', 'DMARC record present but malformed'])
		expect(wrapper.vm.checkingId).toBeNull()
	})

	it('reports a check that fails and frees the button', async () => {
		http.post.mockRejectedValueOnce(new Error(''))
		const wrapper = await mounted()
		await button(wrapper, 'Check now', 0).trigger('click')
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('Failed to check deliverability.')
		expect(wrapper.vm.checkingId).toBeNull()
	})

	it('reloads the list from the Refresh button', async () => {
		const wrapper = await mounted()
		await button(wrapper, 'Refresh').trigger('click')
		await flushPromises()
		expect(store.fetchCollection).toHaveBeenCalledTimes(2)
	})
})
