// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The connection audit page: one read fills the table, an empty answer shows
 * what to connect, each follow verdict reads Yes, No or "Cannot be checked"
 * (and anything else as the last), a handle links out when it has a URL, and
 * "Check again" asks for a fresh audit and reports a failure.
 *
 * @spec openspec/specs/marketing-analytics-connectors/spec.md#requirement-the-connection-audit-page-reads-one-collection-and-renders-the-reasons
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], render() { return h('div', { class: 'empty' }, this.name) } },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('div', { class: 'spinner' }) },
}))

const translate = (_app, text) => text
globalThis.t = translate

const { default: ConnectionAudit } = await import('../../src/views/marketing/ConnectionAudit.vue')

const URL = '/index.php/apps/pipelinq/api/marketing/connection-audit'

/**
 * @return {Promise<object>} The page after its first read.
 */
async function loaded() {
	const wrapper = mount(ConnectionAudit, { global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axios.get.mockReset()
})

describe('the connection audit page', () => {
	it('shows the spinner until the first read answers', () => {
		axios.get.mockReturnValue(new Promise(() => {}))
		const wrapper = mount(ConnectionAudit, { global: { mocks: { t: translate } } })

		expect(wrapper.find('.spinner').exists()).toBe(true)
		expect(axios.get).toHaveBeenCalledWith(URL, { params: { refresh: 'false' } })
	})

	it('renders a row per counterpart with its verdicts and reason', async () => {
		axios.get.mockResolvedValueOnce({
			data: {
				rows: [
					{ counterpartHandle: '@gemeente@social.nl', counterpartUrl: 'https://social.nl/@gemeente', network: 'mastodon', weFollowThem: 'yes', theyFollowUs: 'no', reason: '' },
					{ counterpartHandle: 'gemeente', network: 'linkedin', weFollowThem: 'unknown', theyFollowUs: 'maybe', reason: 'LinkedIn does not publish followers.' },
				],
			},
		})
		const wrapper = await loaded()
		const rows = wrapper.findAll('tbody tr')

		expect(rows).toHaveLength(2)
		const first = rows[0].findAll('td')
		expect(first[0].find('a').attributes()).toMatchObject({ href: 'https://social.nl/@gemeente', target: '_blank', rel: 'noopener noreferrer' })
		expect(first.map((td) => td.text())).toEqual(['@gemeente@social.nl', 'mastodon', 'Yes', 'No', ''])
		expect(first[2].classes()).toEqual(['connection-audit__verdict'])

		const second = rows[1].findAll('td')
		expect(second[0].find('a').exists()).toBe(false)
		expect(second.map((td) => td.text())).toEqual(['gemeente', 'linkedin', 'Cannot be checked', 'Cannot be checked', 'LinkedIn does not publish followers.'])
		expect(second[2].classes()).toContain('connection-audit__verdict--unknown')
		expect(second[3].classes()).not.toContain('connection-audit__verdict--unknown')
		expect(wrapper.find('.spinner').exists()).toBe(false)
	})

	it('shows what to connect when there is nothing to compare', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const wrapper = await loaded()

		expect(wrapper.find('.empty').text()).toBe('Nothing to compare yet')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows the server\'s reason when the read fails, else a general message', async () => {
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'No social accounts.' } } })
		const wrapper = await loaded()
		expect(wrapper.find('[role="alert"]').text()).toBe('No social accounts.')

		axios.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.fetchRows()
		expect(wrapper.vm.error).toBe('Could not load the connection audit.')
	})

	it('asks for a fresh audit on "Check again", disabled until it answers, and clears an old error', async () => {
		axios.get.mockRejectedValueOnce(new Error('down'))
		const wrapper = await loaded()
		let answer
		axios.get.mockReturnValueOnce(new Promise((resolve) => { answer = resolve }))

		await wrapper.find('button').trigger('click')
		expect(axios.get).toHaveBeenLastCalledWith(URL, { params: { refresh: 'true' } })
		expect(wrapper.find('button').attributes('disabled')).toBeDefined()
		expect(wrapper.vm.error).toBe('')

		answer({ data: { rows: [{ counterpartHandle: 'a', network: 'bluesky', weFollowThem: 'yes', theyFollowUs: 'yes' }] } })
		await flushPromises()
		expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
	})

	it('keys a row by network and handle', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const wrapper = await loaded()

		expect([wrapper.vm.keyOf({ network: 'x', counterpartHandle: 'h' }), wrapper.vm.keyOf(null)]).toEqual(['x:h', ':'])
	})

	it('marks a missing verdict as one that cannot be checked', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		const wrapper = await loaded()

		expect(wrapper.vm.verdictLabel(undefined)).toBe('Cannot be checked')
		expect(wrapper.vm.verdictClass(undefined)).toBe('connection-audit__verdict connection-audit__verdict--unknown')
		expect(wrapper.vm.verdictClass('no')).toBe('connection-audit__verdict')
	})
})
