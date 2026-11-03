import { flushPromises, mount } from '@vue/test-utils'
// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqSegmentBouwer: the segment editor groups its rules. SegmentBuilder
 * mounts nc-vue's CnConditionGroupEditor with pipelinq's fields and operators,
 * keeps the debounced preview and the estimate note, and reads a stored
 * `rules` that is not a group as one AND group (design D3). Only a save
 * writes that group form back.
 *
 * The marketing-ui scenarios are asserted here at the component boundary:
 * the editor itself (radios, captions, Add group on the root only, NOT as a
 * locked row) is nc-vue's and has its own tests there.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#requirement-a-segment-groups-its-rules
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({
	post: vi.fn(() => Promise.resolve({ data: { valid: true, estimatedSize: 412 } })),
}))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { render: () => h('span') },
	NcNoteCard: { render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnConditionGroupEditor: {
		name: 'CnConditionGroupEditor',
		props: ['modelValue', 'fields', 'operators', 'maxDepth', 'errors', 'emptyText'],
		emits: ['update:modelValue'],
		render: () => h('div', { class: 'group-editor' }),
	},
}))

globalThis.t = (app, text) => text

const { default: SegmentBuilder } = await import('../../src/components/SegmentBuilder.vue')
const { cloneTree, tally } = await import('../../src/services/segmentRules.js')
const { segmentOperatorsFor } = await import('../../src/services/segmentOperators.js')

const FIELDS = [
	{ value: 'place', label: 'Place', type: 'string' },
	{ value: 'industry', label: 'Industry', type: 'string' },
	{ value: 'pipelinqLastContactAt', label: 'Last contact', type: 'string', format: 'date' },
]

const PLACE = { field: 'place', operator: 'equals', value: 'Zuiddrecht' }
const BOARD_TREE = {
	type: 'AND',
	children: [
		PLACE,
		{
			type: 'OR',
			children: [
				{ field: 'industry', operator: 'equals', value: 'horeca' },
				{ field: 'industry', operator: 'equals', value: 'detailhandel' },
			],
		},
		{ field: 'pipelinqLastContactAt', operator: 'after', value: '2025-11-01' },
	],
}

/**
 * Mount the builder over a tree.
 *
 * @param {object} modelValue The rules.
 * @return {object} The wrapper.
 */
function builder(modelValue) {
	return mount(SegmentBuilder, {
		props: { modelValue, entityType: 'customer', fieldOptions: FIELDS },
		global: { mocks: { t: globalThis.t } },
	})
}

/**
 * @param {object} w The wrapper.
 * @return {object} The group editor stub.
 */
function editorOf(w) {
	return w.findComponent({ name: 'CnConditionGroupEditor' })
}

beforeEach(() => {
	vi.useFakeTimers()
	http.post.mockClear()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('cloneTree reads stored rules as one group (D3)', () => {
	it('keeps a group, upper-casing its type', () => {
		expect(cloneTree({ type: 'or', children: [PLACE] })).toEqual({ type: 'OR', children: [PLACE] })
		expect(cloneTree(BOARD_TREE)).toEqual(BOARD_TREE)
		expect(cloneTree(BOARD_TREE)).not.toBe(BOARD_TREE)
	})

	it('reads a bare condition as an AND group of it', () => {
		expect(cloneTree(PLACE)).toEqual({ type: 'AND', children: [PLACE] })
	})

	it('reads a list of nodes as an AND group of them', () => {
		const list = [PLACE, { type: 'OR', children: [PLACE] }]
		expect(cloneTree(list)).toEqual({ type: 'AND', children: list })
	})

	it('reads children without a type as an AND group', () => {
		expect(cloneTree({ children: [PLACE] })).toEqual({ type: 'AND', children: [PLACE] })
	})

	it('reads empty or missing rules as an empty AND group', () => {
		for (const empty of [null, undefined, '', {}, []]) {
			expect(cloneTree(empty)).toEqual({ type: 'AND', children: [] })
		}
	})

	it('counts an empty inner group as unfinished', () => {
		expect(tally({ type: 'AND', children: [PLACE, { type: 'OR', children: [] }] }).unfinished).toBe(1)
		expect(tally(BOARD_TREE)).toEqual({ leaves: 4, unfinished: 0 })
	})
})

describe('the operators pipelinq passes', () => {
	it('keeps SegmentService names, with before and after on a date field', () => {
		expect(segmentOperatorsFor(FIELDS[0]).map((o) => o.value)).toEqual(['equals', 'notEquals', 'contains'])
		expect(segmentOperatorsFor(FIELDS[2]).map((o) => o.value)).toEqual(['equals', 'notEquals', 'contains', 'before', 'after'])
		expect(segmentOperatorsFor({ type: 'integer' }).map((o) => o.value)).toContain('greaterThanOrEqual')
		expect(segmentOperatorsFor(undefined).map((o) => o.value)).toEqual(['equals', 'notEquals', 'contains'])
	})
})

describe('SegmentBuilder mounts CnConditionGroupEditor', () => {
	it('passes the tree in, with the fields, the operators and one level of groups', () => {
		const w = builder(BOARD_TREE)
		const editor = editorOf(w)
		expect(editor.props('modelValue')).toEqual(BOARD_TREE)
		expect(editor.props('maxDepth')).toBe(1)
		expect(editor.props('fields').map((f) => f.value)).toEqual(['place', 'industry', 'pipelinqLastContactAt'])
		expect(editor.props('operators')(FIELDS[2]).map((o) => o.value)).toContain('after')
		expect(editor.props('emptyText')).toBe('No conditions yet. Add a condition to decide who belongs to this segment.')
	})

	it('opens a bare stored condition as the root group, without emitting', () => {
		const w = builder(PLACE)
		expect(editorOf(w).props('modelValue')).toEqual({ type: 'AND', children: [PLACE] })
		expect(w.emitted('update:modelValue')).toBeUndefined()
	})

	it('passes an edited tree out and previews the whole tree once after 400 ms', async () => {
		const w = builder({ type: 'AND', children: [] })
		editorOf(w).vm.$emit('update:modelValue', BOARD_TREE)
		await w.vm.$nextTick()
		expect(w.emitted('update:modelValue').at(-1)[0]).toEqual(BOARD_TREE)

		vi.advanceTimersByTime(399)
		expect(http.post).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		await flushPromises()
		expect(http.post).toHaveBeenCalledTimes(1)
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/segments/preview', {
			entityType: 'customer',
			rules: BOARD_TREE,
		})
		expect(w.find('.segment-builder__count').text()).toContain('412')
		expect(w.emitted('validityChange').at(-1)[0]).toBe(true)
	})

	it('sends no preview while a group is empty, and says why', async () => {
		const w = builder({ type: 'AND', children: [PLACE] })
		vi.advanceTimersByTime(400)
		await flushPromises()
		http.post.mockClear()

		editorOf(w).vm.$emit('update:modelValue', { type: 'AND', children: [PLACE, { type: 'AND', children: [] }] })
		await w.vm.$nextTick()
		vi.advanceTimersByTime(1000)
		await flushPromises()
		expect(http.post).not.toHaveBeenCalled()
		expect(w.text()).toContain('Complete every condition to see the estimate.')
		expect(w.emitted('statusChange').at(-1)[0]).toBe('incomplete')
	})

	it('shows a server error on the row its path names', async () => {
		http.post.mockResolvedValueOnce({ data: { valid: false, error: '$.children[1].children[0]: field "x" is not declared on the entity schema.' } })
		const w = builder(BOARD_TREE)
		vi.advanceTimersByTime(400)
		await flushPromises()
		expect(editorOf(w).props('errors')).toEqual({ '$.children[1].children[0]': 'field "x" is not declared on the entity schema.' })
	})
})

describe('the old editor is gone', () => {
	it('removes SegmentRuleNode', () => {
		expect(existsSync(resolve(__dirname, '../../src/components/SegmentRuleNode.vue'))).toBe(false)
	})

	it('saves the normalised tree from the form dialog', () => {
		const src = readFileSync(resolve(__dirname, '../../src/dialogs/SegmentFormDialog.vue'), 'utf8')
		expect(src).toMatch(/rules:\s*cloneTree\(this\.model\.rules\)/)
	})
})
