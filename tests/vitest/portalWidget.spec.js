// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The embeddable portal widget: it shows the dashboard to a signed-in
 * customer and the login otherwise, and only when it runs inside a frame does
 * it tell the host page its height (on mount and on every resize) and that
 * the customer logged out. Its observer is released on unmount.
 *
 * @spec openspec/specs/customer-portal/spec.md
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ getToken: vi.fn() }))
vi.mock('../../src/portal/portalApi.js', () => api)
vi.mock('../../src/portal/views/PortalDashboard.vue', () => ({
	default: {
		name: 'PortalDashboard',
		props: { embedded: Boolean },
		emits: ['logout'],
		render() {
			return h('div', { class: 'dashboard', 'data-embedded': String(this.embedded) })
		},
	},
}))
vi.mock('../../src/portal/views/PortalLogin.vue', () => ({
	default: {
		name: 'PortalLogin',
		props: { embedded: Boolean },
		render() {
			return h('div', { class: 'login', 'data-embedded': String(this.embedded) })
		},
	},
}))

const { default: PortalWidget } = await import('../../src/portal/views/PortalWidget.vue')

/** The ResizeObserver the widget created, if any. */
let observer = null

/**
 * A ResizeObserver double recording what it observes.
 */
class FakeResizeObserver {
	/**
	 * @param {Function} callback Called on a resize.
	 */
	constructor(callback) {
		this.callback = callback
		this.observed = []
		this.disconnect = vi.fn()
		observer = this
	}

	/**
	 * @param {Element} element The element to watch.
	 */
	observe(element) {
		this.observed.push(element)
	}
}

/**
 * Run the widget inside a frame (or not) for this test.
 *
 * @param {boolean} framed Whether window.top differs from window.self.
 */
function framed(framed) {
	Object.defineProperty(window, 'top', { configurable: true, get: () => (framed ? {} : window) })
}

let post

beforeEach(() => {
	observer = null
	api.getToken.mockReset()
	globalThis.ResizeObserver = FakeResizeObserver
	post = vi.spyOn(window.parent, 'postMessage').mockImplementation(() => {})
})

afterEach(() => {
	delete globalThis.ResizeObserver
	framed(false)
	post.mockRestore()
})

describe('PortalWidget', () => {
	it('shows the dashboard to a signed-in customer, embedded', () => {
		api.getToken.mockReturnValue('tok')
		framed(false)
		const wrapper = mount(PortalWidget)

		expect(wrapper.find('.dashboard').attributes('data-embedded')).toBe('true')
		expect(wrapper.find('.login').exists()).toBe(false)
	})

	it('shows the login without a token', () => {
		api.getToken.mockReturnValue(null)
		framed(false)
		const wrapper = mount(PortalWidget)

		expect(wrapper.find('.login').attributes('data-embedded')).toBe('true')
		expect(wrapper.find('.dashboard').exists()).toBe(false)
	})

	it('inside a frame, reports its height on mount and on every resize', () => {
		api.getToken.mockReturnValue('tok')
		framed(true)
		const wrapper = mount(PortalWidget)
		const root = wrapper.find('.portal-widget').element
		Object.defineProperty(root, 'scrollHeight', { configurable: true, value: 640 })

		expect(observer.observed).toEqual([root])
		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][1]).toBe('*')
		expect(post.mock.calls[0][0].type).toBe('portal:resize')

		observer.callback()
		expect(post).toHaveBeenLastCalledWith({ type: 'portal:resize', height: 640 }, '*')
	})

	it('inside a frame, tells the host page the customer logged out', () => {
		api.getToken.mockReturnValue('tok')
		framed(true)
		const wrapper = mount(PortalWidget)
		post.mockClear()

		wrapper.findComponent({ name: 'PortalDashboard' }).vm.$emit('logout')

		expect(post).toHaveBeenCalledWith({ type: 'portal:logout' }, '*')
	})

	it('outside a frame, tells the page nothing', () => {
		api.getToken.mockReturnValue('tok')
		framed(false)
		const wrapper = mount(PortalWidget)

		observer.callback()
		wrapper.findComponent({ name: 'PortalDashboard' }).vm.$emit('logout')

		expect(post).not.toHaveBeenCalled()
	})

	it('releases its observer on unmount, and works without ResizeObserver', () => {
		api.getToken.mockReturnValue('tok')
		framed(true)
		mount(PortalWidget).unmount()
		expect(observer.disconnect).toHaveBeenCalledTimes(1)

		delete globalThis.ResizeObserver
		observer = null
		const wrapper = mount(PortalWidget)
		expect(observer).toBeNull()
		expect(post).toHaveBeenCalledTimes(2)
		wrapper.unmount()
	})
})
