// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Add attendee on an event (marketing-events REQ-MEV-004): the event page's
 * More menu opens the library's action dialog, which posts a name and an
 * address to pipelinq's registrations endpoint, so a hand-added attendee goes
 * through the same seat count, waiting list and confirmation as a form sign-up.
 *
 * @spec openspec/changes/marketing-events/tasks.md#4-pages
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const post = vi.fn()
const showSuccess = vi.fn()
const emitted = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post, put: vi.fn(), patch: vi.fn(), delete: vi.fn() } }))
vi.mock('@nextcloud/dialogs', async (importOriginal) => ({ ...(await importOriginal()), showSuccess, showError: vi.fn() }))
vi.mock('@nextcloud/event-bus', async (importOriginal) => ({ ...(await importOriginal()), emit: emitted }))
vi.mock('@nextcloud/router', async (importOriginal) => ({ ...(await importOriginal()), generateUrl: (path) => path }))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), translate: (_app, text) => text, translatePlural: (_app, one) => one }))

const root = resolve(__dirname, '../..')
const read = (...parts) => readFileSync(resolve(root, ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.d', '78-campaign-events.json'))
const detail = manifest.pages.find((page) => page.id === 'CampaignEventDetail')
const action = detail.config.headerActions.find((entry) => entry.id === 'add-attendee')

/**
 * A stub that renders its slots and forwards clicks and typed values.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'label', 'modelValue', 'disabled'],
		emits: ['click', 'update:modelValue'],
		render() {
			return h(tag, {
				'data-stub': name,
				'data-testid': this.$attrs['data-testid'],
				'data-label': this.label,
				disabled: this.disabled,
				onClick: () => this.$emit('click'),
			}, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	}
}

const { default: CnActionDialog } = await import('@conduction/nextcloud-vue/src/components/CnActionDialog/CnActionDialog.vue')

/**
 * Mount the dialog as the action opens it, for event ev-1.
 *
 * @return {object} The wrapper.
 */
function mountDialog() {
	return mount(CnActionDialog, {
		props: { ...action.props, objectId: 'ev-1' },
		global: {
			mocks: { t: (_app, text) => text, n: (_app, one) => one },
			stubs: { CnDialog: stub('CnDialog'), NcButton: stub('NcButton', 'button'), NcTextField: stub('NcTextField'), CnFormField: stub('CnFormField'), CnIcon: true },
		},
	})
}

/**
 * Type a value into the text field with this label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The field's label.
 * @param {string} value The value.
 * @return {Promise<void>}
 */
async function type(wrapper, label, value) {
	const field = wrapper.findAllComponents({ name: 'NcTextField' }).find((candidate) => candidate.props('label') === label)
	field.vm.$emit('update:modelValue', value)
	await flushPromises()
}

describe('the Add attendee action', () => {
	it('opens the library action dialog from the event page, in the More menu', () => {
		expect(action.type).toBe('open-modal')
		expect(action.target).toBe('CnActionDialog')
		expect(action.props.objectId).toBe('@objectId')
		expect(detail.config.quickActions).not.toContain('add-attendee')
	})

	it('is offered only while the event takes registrations', () => {
		expect(action.visibleWhen).toEqual({ field: 'signupStatus', op: 'in', value: ['open', 'full'] })
	})

	it('posts to the registrations route pipelinq declares', () => {
		const request = action.props.config.confirm.request
		expect(request).toEqual({ method: 'POST', url: '/apps/pipelinq/api/campaign-events/@objectId/registrations' })
		expect(read('appinfo', 'routes.php')).toMatch(/'eventAttendance#add', 'url' => '\/api\/campaign-events\/\{id\}\/registrations', 'verb' => 'POST'/)
	})

	it('asks for an address and leaves the name optional, as the endpoint does', () => {
		const fields = action.props.config.fields
		expect(fields.map((field) => field.field)).toEqual(['name', 'email'])
		expect(fields[0].required).toBe(false)
		expect(fields[1].required).not.toBe(false)
		expect(read('lib', 'Controller', 'EventAttendanceController.php')).toMatch(/function add\(string \$id, string \$name = '', string \$email = ''\)/)
	})

	it('uses an icon the app registers', () => {
		expect(read('src', 'icons.js')).toMatch(new RegExp(`^\\s+${action.icon},$`, 'm'))
		expect(action.props.config.confirm.icon).toBe(action.icon)
	})

	it('has every text in English and Dutch', () => {
		const config = action.props.config
		const texts = [action.label, config.title, config.subtitle, config.confirm.label, config.confirm.errorText, config.doneText]
		config.fields.forEach((field) => texts.push(field.label, ...(field.hint ? [field.hint] : [])))
		const en = JSON.parse(read('l10n', 'en.json')).translations
		const nl = JSON.parse(read('l10n', 'nl.json')).translations
		for (const text of texts) {
			expect(en[text], text).toBe(text)
			expect(nl[text], text).toBeTruthy()
			expect(nl[text], text).not.toBe(text)
		}
	})
})

describe('the Add attendee dialog', () => {
	beforeEach(() => {
		post.mockReset()
		showSuccess.mockClear()
		emitted.mockClear()
	})

	it('cannot be confirmed without an address', async () => {
		const wrapper = mountDialog()
		await flushPromises()
		await type(wrapper, 'Name', 'Jan Jansen')

		expect(wrapper.find('[data-testid="cn-action-dialog-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('posts the name and address for this event, refreshes the page and closes', async () => {
		post.mockResolvedValue({ data: { outcome: 'registered', registration: { id: 'r-1' } } })
		const wrapper = mountDialog()
		await flushPromises()
		await type(wrapper, 'Name', 'Jan Jansen')
		await type(wrapper, 'Email address', 'jan@example.nl')
		await wrapper.find('[data-testid="cn-action-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith('/apps/pipelinq/api/campaign-events/ev-1/registrations', { name: 'Jan Jansen', email: 'jan@example.nl' })
		expect(emitted).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(showSuccess).toHaveBeenCalledWith('The attendee is on the registrations list.')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('shows the refusal the server gives and stays open', async () => {
		post.mockRejectedValue({ response: { status: 409, data: { message: 'The event does not take registrations' } } })
		const wrapper = mountDialog()
		await flushPromises()
		await type(wrapper, 'Email address', 'jan@example.nl')
		await wrapper.find('[data-testid="cn-action-dialog-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('The event does not take registrations')
		expect(showSuccess).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
