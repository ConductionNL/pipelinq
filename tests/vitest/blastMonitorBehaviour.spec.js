import axios from '@nextcloud/axios'
// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The send monitor's behaviour: cancelling a blast (and what a refused cancel
 * says), polling that stops once the blast is done, the fallback deliveries
 * fetch, the progress figure and ETA, and how addresses, states and times are
 * shown on a shared screen.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-live-send-monitor
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn() },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['variant', 'disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { template: '<i />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnBuildiqEditButton: { template: '<span />' },
	CnStatusBadge: { props: ['label'], template: '<span class="badge">{{ label }}</span>' },
}))

globalThis.t = (_app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
const { default: BlastMonitor } = await import('../../src/views/blasts/BlastMonitor.vue')

/**
 * Answer the blast read with this blast; deliveries with these rows.
 *
 * @param {object} blast The blast payload.
 * @param {Array|Error} deliveries The deliveries answer, or an error to throw.
 */
function serve(blast, deliveries = []) {
	axios.get.mockImplementation(async (url) => {
		if (url.endsWith('/deliveries')) {
			if (deliveries instanceof Error) {
				throw deliveries
			}
			return { data: { results: deliveries } }
		}
		return { data: { data: blast } }
	})
}

/**
 * @return {Promise<object>} The mounted monitor for blast b1.
 */
async function mountMonitor() {
	const wrapper = mount(BlastMonitor, {
		props: { id: 'b1' },
		global: { mocks: { t: globalThis.t }, stubs: { 'router-link': { template: '<a><slot /></a>' } } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
	axios.get.mockReset()
	axios.post.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('cancelling a blast', () => {
	it('posts the cancel and shows the blast as cancelling', async () => {
		serve({ id: 'b1', status: 'sending', totals: { queued: 3, sent: 1 } })
		axios.post.mockResolvedValue({ data: {} })
		const wrapper = await mountMonitor()

		await wrapper.find('.blast-monitor__cancel').trigger('click')
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith('/apps/pipelinq/api/blasts/b1/cancel')
		expect(wrapper.vm.blast.status).toBe('cancelling')
		expect(wrapper.vm.isSending).toBe(true)
		expect(wrapper.vm.cancelling).toBe(false)
		expect(wrapper.vm.cancelError).toBe('')
		expect(wrapper.find('.blast-monitor__cancel').exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows the server\'s reason when the cancel is refused', async () => {
		serve({ id: 'b1', status: 'scheduled', totals: {} })
		axios.post.mockRejectedValue({ response: { data: { error: 'Already sent' } } })
		const wrapper = await mountMonitor()

		await wrapper.vm.cancel()

		expect(wrapper.vm.cancelError).toBe('Already sent')
		expect(wrapper.vm.blast.status).toBe('scheduled')
		expect(wrapper.vm.cancelling).toBe(false)
		wrapper.unmount()
	})

	it('falls back to its own sentence when the refusal names no reason', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} })
		axios.post.mockRejectedValue(new Error('network'))
		const wrapper = await mountMonitor()

		await wrapper.vm.cancel()

		expect(wrapper.vm.cancelError).toBe('Could not cancel this blast.')
		wrapper.unmount()
	})

	it('offers no cancel on a blast that is already sent', async () => {
		serve({ id: 'b1', status: 'sent', totals: { sent: 2 } })
		const wrapper = await mountMonitor()

		expect(wrapper.vm.canCancel).toBe(false)
		expect(wrapper.vm.isSending).toBe(false)
		expect(wrapper.find('.blast-monitor__cancel').exists()).toBe(false)
		wrapper.unmount()
	})
})

describe('polling', () => {
	it('polls every two seconds while sending and stops once the blast is done', async () => {
		serve({ id: 'b1', status: 'sending', totals: { queued: 1 } })
		const wrapper = await mountMonitor()
		const reads = () => axios.get.mock.calls.filter(([url]) => url === '/apps/pipelinq/api/blasts/b1').length
		expect(reads()).toBe(1)

		vi.advanceTimersByTime(2000)
		await flushPromises()
		expect(reads()).toBe(2)

		serve({ id: 'b1', status: 'sent', totals: { sent: 1 } })
		vi.advanceTimersByTime(2000)
		await flushPromises()
		expect(reads()).toBe(3)
		expect(wrapper.vm.pollHandle).toBeNull()

		vi.advanceTimersByTime(10000)
		await flushPromises()
		expect(reads()).toBe(3)
		wrapper.unmount()
	})

	it('starts only one polling loop', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} })
		const wrapper = await mountMonitor()
		const handle = wrapper.vm.pollHandle

		wrapper.vm.startPolling()

		expect(wrapper.vm.pollHandle).toBe(handle)
		wrapper.unmount()
	})

	it('keeps polling through a failed read and stops loading', async () => {
		axios.get.mockRejectedValue(new Error('offline'))
		const wrapper = await mountMonitor()

		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.blast).toBeNull()
		expect(wrapper.vm.pollHandle).not.toBeNull()
		wrapper.unmount()
	})

	it('reads the deliveries separately when the blast does not embed them', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} }, [{ id: 'd1', email: 'anna@ziggo.example', sentAt: '2026-10-29T10:00:00Z' }])
		const wrapper = await mountMonitor()

		expect(axios.get).toHaveBeenCalledWith('/apps/pipelinq/api/blasts/b1/deliveries', { params: { limit: 50 } })
		expect(wrapper.vm.timeline).toEqual([{ id: 'd1', contactId: undefined, email: 'anna@ziggo.example', phone: undefined, status: 'queued', timestamp: '2026-10-29T10:00:00Z' }])
		wrapper.unmount()
	})

	it('shows no events when the deliveries cannot be read', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} }, new Error('500'))
		const wrapper = await mountMonitor()

		expect(wrapper.vm.timeline).toEqual([])
		expect(wrapper.vm.buildTimeline(null)).toEqual([])
		wrapper.unmount()
	})
})

describe('progress', () => {
	it('is 0% with no audience and rounds the processed share otherwise', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} })
		const wrapper = await mountMonitor()
		expect(wrapper.vm.progressPercent).toBe(0)
		expect(wrapper.vm.etaLabel).toBe('')

		wrapper.vm.blast = { status: 'sending', totals: { queued: 2, sent: 1 } }
		expect(wrapper.vm.progressPercent).toBe(33)
		wrapper.unmount()
	})

	it('estimates the seconds left from the rate so far', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} })
		const wrapper = await mountMonitor()

		vi.advanceTimersByTime(10000)
		wrapper.vm.blast = { status: 'sending', totals: { queued: 20, sent: 10 } }

		expect(wrapper.vm.etaLabel).toBe('About 20 s to go')
		wrapper.unmount()
	})

	it('says nothing once less than a second is left', async () => {
		serve({ id: 'b1', status: 'sending', totals: {} })
		const wrapper = await mountMonitor()

		vi.advanceTimersByTime(10000)
		wrapper.vm.blast = { status: 'sending', totals: { queued: 0, sent: 10 } }

		expect(wrapper.vm.etaLabel).toBe('')
		wrapper.unmount()
	})
})

describe('what the monitor shows', () => {
	it('masks addresses for a shared screen', async () => {
		serve({ id: 'b1', status: 'sent', totals: {} })
		const wrapper = await mountMonitor()
		const { maskAddress } = wrapper.vm

		expect(maskAddress('anna@ziggo.nl')).toBe('a***@ziggo.nl')
		expect(maskAddress('0612345678')).toBe('******5678')
		expect(maskAddress('c-12')).toBe('c-12')
		expect(maskAddress('')).toBe('—')
		expect(maskAddress(null)).toBe('—')
		wrapper.unmount()
	})

	it('tones states and tiles, plain when unknown', async () => {
		serve({ id: 'b1', status: 'sent', totals: {} })
		const wrapper = await mountMonitor()

		expect(wrapper.vm.statusVariant('bounced')).toBe('error')
		expect(wrapper.vm.statusVariant('cancelling')).toBe('warning')
		expect(wrapper.vm.statusVariant('mystery')).toBe('default')
		expect(wrapper.vm.totalTone('delivered')).toBe('success')
		expect(wrapper.vm.totalTone('bounced')).toBe('error')
		expect(wrapper.vm.totalTone('sent')).toBe('plain')
		wrapper.unmount()
	})

	it('labels states, the queue tile and unknown states as given', async () => {
		serve({ id: 'b1', status: 'sent', totals: {} })
		const wrapper = await mountMonitor()

		expect(wrapper.vm.statusLabel('bounced')).toBe('Could not be delivered')
		expect(wrapper.vm.totalLabel('queued')).toBe('In the queue')
		expect(wrapper.vm.totalLabel('opened')).toBe('Opened')
		expect(wrapper.vm.statusLabel('mystery')).toBe('mystery')
		wrapper.unmount()
	})

	it('captions tiles with their share, and nothing for an unknown tile', async () => {
		serve({ id: 'b1', status: 'sent', totals: { sent: 8, delivered: 4, opened: 1, clicked: 0 } })
		const wrapper = await mountMonitor()

		expect(wrapper.vm.totalCaption('delivered')).toBe('50% of sent')
		expect(wrapper.vm.totalCaption('opened')).toBe('25% of delivered')
		expect(wrapper.vm.totalCaption('clicked')).toBe('0% of delivered')
		expect(wrapper.vm.totalCaption('bounced')).toBe('address refused')
		expect(wrapper.vm.totalCaption('mystery')).toBe('')
		wrapper.vm.blast = { status: 'sent', totals: {} }
		expect(wrapper.vm.totalCaption('delivered')).toBe('0% of sent')
		wrapper.unmount()
	})

	it('shows an empty or unparseable time as given', async () => {
		serve({ id: 'b1', status: 'sent', totals: {} })
		const wrapper = await mountMonitor()

		expect(wrapper.vm.formatTime('')).toBe('')
		expect(wrapper.vm.formatTime('not a time')).toBe('not a time')
		wrapper.unmount()
	})
})
