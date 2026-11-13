/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The validity a hosted lead or request form surfaces to its host.
 *
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-leads-ui/tasks.md#task-45
 */
import { describe, expect, it, vi } from 'vitest'
import mixin from '../../src/mixins/hostedFormMixin.js'

/**
 * @param {object} errors The host's errors.
 * @param {string|undefined} title The form title.
 * @return {boolean} Whether the form counts as valid.
 */
const valid = (errors, title) => !!mixin.computed.isValid.call({ errors, form: { title } })

describe('hostedFormMixin', () => {
	it('shows the built-in actions unless the host opts out', () => {
		expect(mixin.props.showActions).toEqual({ type: Boolean, default: true })
	})

	it('is valid with a title and no errors', () => {
		expect(valid({}, 'Lead')).toBe(true)
	})

	it('is invalid with any error', () => {
		expect(valid({ title: 'Required' }, 'Lead')).toBe(false)
	})

	it('is invalid with a blank, whitespace-only or missing title', () => {
		expect(valid({}, '')).toBe(false)
		expect(valid({}, '   ')).toBe(false)
		expect(valid({}, undefined)).toBe(false)
	})

	it('surfaces validity immediately, as a boolean', () => {
		const vm = { $emit: vi.fn() }
		expect(mixin.watch.isValid.immediate).toBe(true)
		mixin.watch.isValid.handler.call(vm, 'Lead')
		mixin.watch.isValid.handler.call(vm, '')
		expect(vm.$emit.mock.calls).toEqual([['update:valid', true], ['update:valid', false]])
	})
})
