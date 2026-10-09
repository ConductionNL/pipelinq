// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The public satisfaction survey (customer-satisfaction-closed-loop, task 4.2).
 *
 * The invitation link opens this page in the customer portal. It asks the
 * survey's questions, sends the opt-out only when the box is ticked, and says
 * the survey is closed when the token was already used or has expired.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()
vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => get(...args),
		post: (...args) => post(...args),
	},
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) =>
		'/index.php' + path.replace('{token}', params.token),
}))

const { default: PublicSurveyForm } =
	await import('../../src/views/surveys/PublicSurveyForm.vue')
const { portalRoutes } = await import('../../src/portal/portalRoutes.js')

const SURVEY = {
	state: 'open',
	survey: {
		title: 'How did we do?',
		questions: [
			{
				key: 'nps',
				label: 'Would you recommend us?',
				kind: 'nps',
				required: true,
			},
			{ key: 'why', label: 'Why?', kind: 'text' },
		],
	},
}

function mountForm() {
	return mount(PublicSurveyForm, {
		props: { token: 'tok-1' },
		global: {
			mocks: {
				t: (_app, text, vars) =>
					vars ? text.replace('{question}', vars.question) : text,
			},
		},
	})
}

describe('PublicSurveyForm', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
	})

	it('is a public portal route, so a respondent needs no account', () => {
		const route = portalRoutes.find((r) => r.path === '/survey/:token')
		expect(route).toBeTruthy()
		expect(route.meta.public).toBe(true)
		expect(route.component === PublicSurveyForm).toBe(true)
	})

	it('reads the survey for the token and draws its questions', async () => {
		get.mockResolvedValue({ data: SURVEY })
		const wrapper = mountForm()
		await flushPromises()
		expect(get).toHaveBeenCalledWith('/index.php/apps/pipelinq/survey/i/tok-1')
		expect(wrapper.text()).toContain('Would you recommend us?')
		expect(wrapper.findAll('input[type="radio"]').length).toBe(11)
		expect(wrapper.find('textarea').exists()).toBe(true)
	})

	it('sends the opt-out when the box is ticked', async () => {
		get.mockResolvedValue({ data: SURVEY })
		post.mockResolvedValue({ data: {} })
		const wrapper = mountForm()
		await flushPromises()
		await wrapper.findAll('input[type="radio"]')[9].setValue(true)
		await wrapper.find('[data-testid="survey-opt-out"]').setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/survey/i/tok-1',
			{ answers: { nps: 9 }, optOut: true },
		)
		expect(wrapper.text()).toContain(
			'We will not send you satisfaction surveys again.',
		)
	})

	it('leaves the opt-out out when the box is not ticked', async () => {
		get.mockResolvedValue({ data: SURVEY })
		post.mockResolvedValue({ data: {} })
		const wrapper = mountForm()
		await flushPromises()
		await wrapper.findAll('input[type="radio"]')[3].setValue(true)
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(post.mock.calls[0][1]).toEqual({ answers: { nps: 3 } })
		expect(wrapper.text()).toContain('Thank you for your answers')
	})

	it('asks for a required answer before sending', async () => {
		get.mockResolvedValue({ data: SURVEY })
		const wrapper = mountForm()
		await flushPromises()
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(post).not.toHaveBeenCalled()
		expect(wrapper.find('[role="alert"]').text()).toContain(
			'Would you recommend us?',
		)
	})

	it('says the survey is closed for a used token', async () => {
		get.mockRejectedValue({
			response: { status: 410, data: { state: 'responded' } },
		})
		const wrapper = mountForm()
		await flushPromises()
		expect(wrapper.text()).toContain('This survey is closed')
		expect(wrapper.text()).toContain('You have already answered this survey.')
		expect(wrapper.find('form').exists()).toBe(false)
	})
})
