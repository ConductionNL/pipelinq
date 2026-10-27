// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The incoming-call card is the caller the screen pop never had. These tests
 * start where a real call starts for the browser, the answer of
 * `GET /api/cti/incoming-call`, and follow it to the lookup, the chooser, the
 * new-contact form and the record that opens.
 *
 * THE FAILURE THIS GUARDS is the one the spec recorded for months: modals with
 * tests of their own and no page that mounts them. So the last block reads
 * App.vue, the only place the card is mounted, and the first block proves the
 * card stays silent on an install without telephony.
 *
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ incomingCall: vi.fn(), screenPop: vi.fn(), submitDisposition: vi.fn() }))
const store = vi.hoisted(() => ({
	liveLastEventAt: 0,
	subscribe: vi.fn(),
	unsubscribe: vi.fn(),
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
}))
const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))

vi.mock('../../src/services/ctiApi.js', () => api)
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => {
	const shell = (name, tag) => ({
		name,
		inheritAttrs: true,
		render() {
			return h(tag, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	return {
		NcButton: shell('NcButton', 'button'),
		NcCheckboxRadioSwitch: shell('NcCheckboxRadioSwitch', 'label'),
		NcDialog: shell('NcDialog', 'div'),
		NcSelect: shell('NcSelect', 'div'),
		NcLoadingIcon: shell('NcLoadingIcon', 'span'),
		NcTextArea: shell('NcTextArea', 'textarea'),
		NcTextField: shell('NcTextField', 'input'),
	}
})

vi.mock('@conduction/nextcloud-vue', () => {
	const shell = (name, tag) => ({
		name,
		inheritAttrs: true,
		props: ['name', 'eyebrow', 'subtitle', 'width', 'text', 'type'],
		render() {
			return h(tag, { 'data-name': this.name, 'data-eyebrow': this.eyebrow, 'data-subtitle': this.subtitle, 'data-text': this.text }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	})
	return { CnDialog: shell('CnDialog', 'div'), CnNoteCard: shell('CnNoteCard', 'div') }
})

globalThis.n = (_app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

globalThis.t = (_app, text, vars = {}) =>
	text.replace(/\{(\w+)\}/g, (_all, key) => vars[key] ?? `{${key}}`)

const { default: IncomingCallCard, POLL_MS } =
	await import('../../src/components/IncomingCallCard.vue')
const { default: ScreenPopModal } =
	await import('../../src/modals/ScreenPopModal.vue')
const { default: NewContactIntakeModal } =
	await import('../../src/modals/NewContactIntakeModal.vue')
const { default: CtiDispositionModal } =
	await import('../../src/modals/CtiDispositionModal.vue')

const CALL = {
	id: 'call-1',
	fromNumber: '+31612345678',
	queueName: 'Vergunningen',
	extension: '2',
	startedAt: '2026-10-12T14:00:00Z',
	answeredAt: '',
}
const SANNE = { id: 'client-sanne', name: 'Sanne de Vries', _matchType: 'client' }
const HENK = { id: 'contact-henk', name: 'Henk de Vries', _matchType: 'contact' }

let push

/**
 * Mount the card and let its first request settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function card() {
	push = vi.fn()
	const wrapper = mount(IncomingCallCard, {
		global: { mocks: { t: globalThis.t, $router: { push, resolve: ({ params }) => ({ href: `/apps/pipelinq/tickets/${params.id}` }) } } },
	})
	await flushPromises()
	await vi.advanceTimersByTimeAsync(0)
	await flushPromises()
	return wrapper
}

/**
 * The element with a test id, or a non-existing wrapper.
 *
 * @param {object} wrapper The mounted card.
 * @param {string} id The data-testid.
 * @return {object} The element wrapper.
 */
function part(wrapper, id) {
	return wrapper.find(`[data-testid="${id}"]`)
}

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-10-12T14:00:08Z'))
	api.incomingCall.mockReset()
	api.screenPop.mockReset()
	store.subscribe.mockReset().mockResolvedValue('handle-1')
	store.unsubscribe.mockReset()
	store.fetchObject
		.mockReset()
		.mockResolvedValue({ id: 'call-1', title: 'CTI inbound call' })
	store.saveObject
		.mockReset()
		.mockImplementation(async (_type, data) => ({ id: 'saved-1', ...data }))
	dialogs.showError.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('an install without telephony', () => {
	it('shows nothing, looks nobody up and does not ask again', async () => {
		api.incomingCall.mockResolvedValue({
			enabled: false,
			delayMs: 0,
			call: null,
		})

		const wrapper = await card()
		await vi.advanceTimersByTimeAsync(POLL_MS * 3)

		expect(wrapper.html()).toBe('<!--v-if-->')
		expect(api.incomingCall).toHaveBeenCalledTimes(1)
		expect(api.screenPop).not.toHaveBeenCalled()
		expect(store.subscribe).not.toHaveBeenCalled()
	})

	it('stays silent when the server refuses or fails', async () => {
		api.incomingCall.mockRejectedValue(
			new Error('Request failed with status code 403'),
		)

		const wrapper = await card()
		await vi.advanceTimersByTimeAsync(POLL_MS * 3)

		expect(wrapper.html()).toBe('<!--v-if-->')
		expect(api.incomingCall).toHaveBeenCalledTimes(1)
		expect(dialogs.showError).not.toHaveBeenCalled()
	})
})

describe('telephony is on and nobody is calling', () => {
	it('shows no card, and keeps watching', async () => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: null })

		const wrapper = await card()

		expect(part(wrapper, 'incoming-call-card').exists()).toBe(false)
		expect(api.screenPop).not.toHaveBeenCalled()
		expect(store.subscribe).toHaveBeenCalledWith('ticket')

		await vi.advanceTimersByTimeAsync(POLL_MS)
		expect(api.incomingCall).toHaveBeenCalledTimes(2)
	})

	it('releases its watch when it is removed', async () => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: null })

		const wrapper = await card()
		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(POLL_MS * 2)

		expect(store.unsubscribe).toHaveBeenCalledWith('handle-1')
		expect(api.incomingCall).toHaveBeenCalledTimes(1)
	})
})

describe('a call with one match', () => {
	beforeEach(() => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: CALL })
		api.screenPop.mockResolvedValue({
			action: 'navigate',
			matches: [SANNE],
			e164: '+31612345678',
			rawNumber: '06 12 34 56 78',
		})
	})

	it('looks the caller up by the number of the call, once', async () => {
		const wrapper = await card()
		await vi.advanceTimersByTimeAsync(POLL_MS)
		await flushPromises()

		expect(api.screenPop).toHaveBeenCalledTimes(1)
		expect(api.screenPop).toHaveBeenCalledWith('+31612345678')
		expect(part(wrapper, 'incoming-call-line').text()).toBe(
			'06 12 34 56 78 · queue Vergunningen · line 2',
		)
		expect(part(wrapper, 'incoming-call-match').text()).toContain(
			'Sanne de Vries',
		)
		expect(part(wrapper, 'incoming-call-match').text()).toContain('SV')
	})

	it('counts the seconds since the call started', async () => {
		const wrapper = await card()
		expect(part(wrapper, 'incoming-call-timer').text()).toBe('0:08')

		await vi.advanceTimersByTimeAsync(2000)
		expect(part(wrapper, 'incoming-call-timer').text()).toBe('0:10')
	})

	it('opens the client and links the call to them', async () => {
		const wrapper = await card()

		await part(wrapper, 'incoming-call-open').trigger('click')
		await flushPromises()

		expect(store.fetchObject).toHaveBeenCalledWith('ticket', 'call-1')
		expect(store.saveObject).toHaveBeenCalledWith('ticket', {
			id: 'call-1',
			title: 'CTI inbound call',
			client: 'client-sanne',
		})
		expect(push).toHaveBeenCalledWith({
			name: 'ClientDetail',
			params: { id: 'client-sanne' },
		})
		expect(part(wrapper, 'incoming-call-card').exists()).toBe(false)
	})

	it('still opens the client when the link cannot be written', async () => {
		store.saveObject.mockRejectedValue(new Error('403'))
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const wrapper = await card()

		await part(wrapper, 'incoming-call-open').trigger('click')
		await flushPromises()

		expect(push).toHaveBeenCalledWith({
			name: 'ClientDetail',
			params: { id: 'client-sanne' },
		})
		warn.mockRestore()
	})

	it('offers a new contact when the agent says it is not this client', async () => {
		const wrapper = await card()

		await part(wrapper, 'incoming-call-reject').trigger('click')

		expect(part(wrapper, 'incoming-call-match').exists()).toBe(false)
		expect(part(wrapper, 'incoming-call-none').exists()).toBe(true)
		expect(part(wrapper, 'incoming-call-new').exists()).toBe(true)
		expect(push).not.toHaveBeenCalled()
	})

	it('stays closed for this call and shows the next one', async () => {
		const wrapper = await card()

		await part(wrapper, 'incoming-call-close').trigger('click')
		await vi.advanceTimersByTimeAsync(POLL_MS)
		await flushPromises()
		expect(part(wrapper, 'incoming-call-card').exists()).toBe(false)

		api.incomingCall.mockResolvedValue({
			enabled: true,
			delayMs: 0,
			call: { ...CALL, id: 'call-2', fromNumber: '+31786123341' },
		})
		await vi.advanceTimersByTimeAsync(POLL_MS)
		await flushPromises()
		// The card shows after the screen-pop delay, which is a timer even at 0 ms.
		await vi.advanceTimersByTimeAsync(10)

		expect(part(wrapper, 'incoming-call-card').exists()).toBe(true)
		expect(api.screenPop).toHaveBeenLastCalledWith('+31786123341')
	})

	it('goes away when the call ends', async () => {
		const wrapper = await card()
		expect(part(wrapper, 'incoming-call-card').exists()).toBe(true)

		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: null })
		store.liveLastEventAt = 1
		await wrapper.vm.refresh()
		await flushPromises()

		expect(part(wrapper, 'incoming-call-card').exists()).toBe(false)
	})

	it('waits for the configured delay before it shows', async () => {
		api.incomingCall.mockResolvedValue({
			enabled: true,
			delayMs: 2000,
			call: CALL,
		})

		const wrapper = await card()
		expect(part(wrapper, 'incoming-call-card').exists()).toBe(false)
		expect(api.screenPop).toHaveBeenCalledWith('+31612345678')

		await vi.advanceTimersByTimeAsync(2000)
		expect(part(wrapper, 'incoming-call-card').exists()).toBe(true)
	})
})

describe('a call with several matches', () => {
	beforeEach(() => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: CALL })
		api.screenPop.mockResolvedValue({
			action: 'chooser',
			matches: [SANNE, HENK],
			e164: '+31612345678',
			rawNumber: '0612345678',
		})
	})

	it('mounts the screen pop with the matches when the agent chooses', async () => {
		const wrapper = await card()
		expect(part(wrapper, 'incoming-call-many').text()).toBe(
			'Found on the number, 2 contacts',
		)
		expect(wrapper.findComponent(ScreenPopModal).exists()).toBe(false)

		await part(wrapper, 'incoming-call-choose').trigger('click')

		const modal = wrapper.findComponent(ScreenPopModal)
		expect(modal.exists()).toBe(true)
		expect(modal.props('matches')).toEqual([SANNE, HENK])
		expect(modal.props('e164')).toBe('+31612345678')
	})

	it('opens the contact the agent selects and links the call', async () => {
		const wrapper = await card()
		await part(wrapper, 'incoming-call-choose').trigger('click')

		wrapper.findComponent(ScreenPopModal).vm.$emit('select', HENK)
		await flushPromises()

		expect(store.saveObject).toHaveBeenCalledWith(
			'ticket',
			expect.objectContaining({ id: 'call-1', contact: 'contact-henk' }),
		)
		expect(push).toHaveBeenCalledWith({
			name: 'ContactDetail',
			params: { id: 'contact-henk' },
		})
		expect(wrapper.findComponent(ScreenPopModal).exists()).toBe(false)
	})

	it('goes from the screen pop to the new-contact form', async () => {
		const wrapper = await card()
		await part(wrapper, 'incoming-call-choose').trigger('click')

		wrapper.findComponent(ScreenPopModal).vm.$emit('intake')
		await flushPromises()

		expect(wrapper.findComponent(ScreenPopModal).exists()).toBe(false)
		expect(wrapper.findComponent(NewContactIntakeModal).props('e164')).toBe(
			'+31612345678',
		)
	})
})

describe('a call from an unknown number', () => {
	beforeEach(() => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: CALL })
		api.screenPop.mockResolvedValue({
			action: 'intake',
			matches: [],
			e164: '+31612345678',
			rawNumber: '',
		})
	})

	it('stores the new contact, links the call with the notes and opens them', async () => {
		const wrapper = await card()
		expect(part(wrapper, 'incoming-call-none').exists()).toBe(true)

		await part(wrapper, 'incoming-call-new').trigger('click')
		wrapper.findComponent(NewContactIntakeModal).vm.$emit('submit', {
			name: ' Ellen Visser ',
			phone: '+31612345678',
			email: '',
			organisation: 'Buurtvereniging Oost',
			notes: 'Vraagt naar de subsidie',
		})
		await flushPromises()

		expect(store.saveObject).toHaveBeenNthCalledWith(1, 'contact', {
			name: 'Ellen Visser',
			phone: '+31612345678',
		})
		expect(store.saveObject).toHaveBeenNthCalledWith(
			2,
			'ticket',
			expect.objectContaining({
				id: 'call-1',
				contact: 'saved-1',
				notes: 'Buurtvereniging Oost\nVraagt naar de subsidie',
			}),
		)
		expect(push).toHaveBeenCalledWith({
			name: 'ContactDetail',
			params: { id: 'saved-1' },
		})
	})

	it('keeps the form open and says so when the contact is not saved', async () => {
		store.saveObject.mockResolvedValue(null)
		const wrapper = await card()
		await part(wrapper, 'incoming-call-new').trigger('click')

		wrapper.findComponent(NewContactIntakeModal).vm.$emit('submit', {
			name: 'Ellen Visser',
			phone: '+31612345678',
			email: 'ellen@example.org',
		})
		await flushPromises()

		expect(store.saveObject).toHaveBeenCalledWith('contact', {
			name: 'Ellen Visser',
			phone: '+31612345678',
			email: 'ellen@example.org',
		})
		expect(dialogs.showError).toHaveBeenCalledTimes(1)
		expect(wrapper.findComponent(NewContactIntakeModal).exists()).toBe(true)
		expect(push).not.toHaveBeenCalled()
	})

	it('reads a failed lookup as no client found', async () => {
		api.screenPop.mockRejectedValue(new Error('500'))

		const wrapper = await card()

		expect(part(wrapper, 'incoming-call-none').exists()).toBe(true)
		expect(part(wrapper, 'incoming-call-line').text()).toContain('+31612345678')
	})
})

describe('a previewed call (callPreview in the address)', () => {
	afterEach(() => {
		window.history.replaceState({}, '', '/')
	})

	it('shows the card for the number without asking for a live call, and writes nothing', async () => {
		window.history.replaceState({}, '', '/?callPreview=0786123341')
		api.screenPop.mockResolvedValue({ action: 'intake', matches: [] })

		const wrapper = await card()

		expect(api.incomingCall).not.toHaveBeenCalled()
		expect(api.screenPop).toHaveBeenCalledWith('0786123341')
		expect(part(wrapper, 'incoming-call-card').exists()).toBe(true)
		expect(part(wrapper, 'incoming-call-none').exists()).toBe(true)
		expect(store.saveObject).not.toHaveBeenCalled()
	})

	it('opens the chooser or the new-contact form when the address asks for it', async () => {
		window.history.replaceState(
			{},
			'',
			'/?callPreview=0786123341&callPreviewOpen=chooser',
		)
		api.screenPop.mockResolvedValue({
			action: 'chooser',
			matches: [SANNE, HENK],
		})
		const chooser = await card()
		expect(chooser.findComponent(ScreenPopModal).exists()).toBe(true)

		window.history.replaceState(
			{},
			'',
			'/?callPreview=0786123341&callPreviewOpen=intake',
		)
		api.screenPop.mockResolvedValue({ action: 'intake', matches: [] })
		const intake = await card()
		expect(intake.findComponent(NewContactIntakeModal).exists()).toBe(true)
	})
})

describe('the app shell', () => {
	const shell = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')

	it('mounts the incoming-call card, which is its only mount', () => {
		expect(shell).toContain(
			"import IncomingCallCard from './components/IncomingCallCard.vue'",
		)
		expect(shell).toMatch(/components:\s*\{[^}]*\bIncomingCallCard\b[^}]*\}/)
		expect(shell.split('<script>')[0]).toMatch(/<IncomingCallCard\s*\/>/)
	})

	it('the card is what mounts the screen pop and asks for the lookup', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/components/IncomingCallCard.vue'),
			'utf8',
		)
		expect(source.split('<script>')[0]).toContain('<ScreenPopModal')
		expect(source.split('<script>')[0]).toContain('<NewContactIntakeModal')
		expect(source).toMatch(/await screenPop\(call\.fromNumber\)/)
	})
})

describe('the wrap-up after the call (board PqGesprekAfronden)', () => {
	afterEach(() => {
		window.history.replaceState({}, '', '/')
	})

	it('opens when a call the agent handled ends, and records the outcome on its contact moment', async () => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: CALL })
		api.screenPop.mockResolvedValue({ action: 'navigate', matches: [SANNE] })
		api.submitDisposition.mockReset().mockResolvedValue({})
		const wrapper = await card()
		await part(wrapper, 'incoming-call-open').trigger('click')
		await flushPromises()

		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: null })
		await wrapper.vm.refresh()
		await flushPromises()

		const wrapUp = wrapper.findComponent(CtiDispositionModal)
		expect(wrapUp.exists()).toBe(true)
		expect(wrapUp.props('clientName')).toBe('Sanne de Vries')
		expect(wrapUp.props('number')).toBe('+31612345678')
		expect(wrapUp.props('recordedHref')).toBe('/apps/pipelinq/tickets/call-1')

		wrapUp.vm.$emit('submit', { subject: 'Podium', outcome: 'resolved', notes: '', queue: '' })
		await flushPromises()
		expect(api.submitDisposition).toHaveBeenCalledWith('call-1', { subject: 'Podium', outcome: 'resolved', notes: '', queue: '' })
		expect(wrapper.findComponent(CtiDispositionModal).exists()).toBe(false)
	})

	it('does not open for a call the agent never took up', async () => {
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: CALL })
		api.screenPop.mockResolvedValue({ action: 'navigate', matches: [SANNE] })
		const wrapper = await card()
		api.incomingCall.mockResolvedValue({ enabled: true, delayMs: 0, call: null })
		await wrapper.vm.refresh()
		await flushPromises()
		expect(wrapper.findComponent(CtiDispositionModal).exists()).toBe(false)
	})

	it('can be previewed from the address, and a previewed wrap-up writes nothing', async () => {
		window.history.replaceState({}, '', '/?callPreview=0641228790&callPreviewOpen=disposition')
		api.screenPop.mockResolvedValue({ action: 'navigate', matches: [SANNE], rawNumber: '06 41 22 87 90' })
		api.submitDisposition.mockReset()
		const wrapper = await card()
		const wrapUp = wrapper.findComponent(CtiDispositionModal)
		expect(wrapUp.exists()).toBe(true)
		wrapUp.vm.$emit('submit', { subject: 'x', outcome: 'resolved', notes: '', queue: '' })
		await flushPromises()
		expect(api.submitDisposition).not.toHaveBeenCalled()
		expect(wrapper.findComponent(CtiDispositionModal).exists()).toBe(false)
	})
})

describe('the new-contact form and the wrap-up as drawn', () => {
	it('names the call, its number as read and its queue over the new-contact form', async () => {
		const { readablePhone } = await import('../../src/utils/readablePhone.js')
		expect(readablePhone('0641228790')).toBe('06 41 22 87 90')
		expect(readablePhone('+31641228790')).toBe('06 41 22 87 90')
		expect(readablePhone('0201234567')).toBe('020 123 4567')
		expect(readablePhone('12345')).toBe('12345')
		const form = mount(NewContactIntakeModal, { props: { prefillPhone: '0641228790', queueName: 'Vergunningen' }, global: { mocks: { t: globalThis.t } } })
		expect(form.find('[data-name]').attributes('data-eyebrow')).toBe('Incoming call · queue Vergunningen')
		expect(form.find('[data-name]').attributes('data-subtitle')).toBe('Incoming call · 06 41 22 87 90 · queue Vergunningen')
		expect(form.vm.form.phone).toBe('06 41 22 87 90')
	})

	it('says how long the call took in words', async () => {
		const { callLength } = await import('../../src/modals/CtiDispositionModal.vue')
		expect(callLength(252)).toBe('4 minutes 12 seconds')
		expect(callLength(9)).toBe('9 seconds')
		expect(callLength(-1)).toBe('')
	})
})

