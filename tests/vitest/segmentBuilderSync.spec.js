/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The segment builder's parts around its live preview: prop defaults, taking
 * a new rule tree from its parent only when it differs, dropping a pending
 * preview on unmount, and where a validator message lands.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnConditionGroupEditor: {} }))
vi.mock('@nextcloud/axios', () => ({ default: { post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('vue-material-design-icons/AccountGroup.vue', () => ({ default: {} }))

const { default: SegmentBuilder } = await import('../../src/components/SegmentBuilder.vue')

afterEach(() => {
	vi.useRealTimers()
})

const LEAF = { field: 'city', operator: 'eq', value: 'Zwolle' }

describe('SegmentBuilder props', () => {
	it('defaults to an empty AND group and no fields', () => {
		expect(SegmentBuilder.props.modelValue.default()).toEqual({ type: 'AND', children: [] })
		expect(SegmentBuilder.props.fieldOptions.default()).toEqual([])
	})

	it('accepts only contacts and customers', () => {
		const valid = SegmentBuilder.props.entityType.validator

		expect(valid('contact')).toBe(true)
		expect(valid('customer')).toBe(true)
		expect(valid('lead')).toBe(false)
	})
})

describe('SegmentBuilder taking a tree from its parent', () => {
	const watcher = SegmentBuilder.watch.modelValue

	it('takes a different tree and evaluates it', () => {
		const ctx = { tree: { type: 'AND', children: [] }, evaluate: vi.fn() }

		watcher.call(ctx, { type: 'or', children: [LEAF] })

		expect(ctx.tree).toEqual({ type: 'OR', children: [LEAF] })
		expect(ctx.evaluate).toHaveBeenCalledTimes(1)
	})

	it('ignores the same tree it just emitted', () => {
		const tree = { type: 'AND', children: [LEAF] }
		const ctx = { tree, evaluate: vi.fn() }

		watcher.call(ctx, { type: 'AND', children: [{ ...LEAF }] })

		expect(ctx.tree).toBe(tree)
		expect(ctx.evaluate).not.toHaveBeenCalled()
	})
})

describe('SegmentBuilder unmounting', () => {
	it('drops a pending preview', () => {
		vi.useFakeTimers()
		const preview = vi.fn()
		const ctx = { previewTimer: setTimeout(preview, 400) }

		SegmentBuilder.beforeUnmount.call(ctx)
		vi.advanceTimersByTime(1000)

		expect(preview).not.toHaveBeenCalled()
	})
})

describe('SegmentBuilder placing a validator message', () => {
	const place = (message) => {
		const ctx = { errors: {}, generalError: '' }
		SegmentBuilder.methods.placeError.call(ctx, message)
		return ctx
	}

	it('pins a message with a node path to that node', () => {
		expect(place('$.children[1].children[0]: field "x" is not declared.')).toEqual({
			errors: { '$.children[1].children[0]': 'field "x" is not declared.' },
			generalError: '',
		})
	})

	it('shows a root-level message without its path as a general error', () => {
		expect(place('$: the group is empty.')).toEqual({ errors: {}, generalError: 'the group is empty.' })
	})

	it('shows a message without a path as it is', () => {
		expect(place('Invalid rules.')).toEqual({ errors: {}, generalError: 'Invalid rules.' })
	})
})
