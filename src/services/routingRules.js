/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The routing rules of the shipped "Route incoming tickets" flow as rows an
 * administrator edits, and back (requests-inbound-to-queue D7). The flow's
 * decision table takes the first rule that matches, so the order of the rows
 * is the order the rules are tried in. The table always ends in the shipped
 * catch-all `everything-else`, which leaves the category empty and the
 * priority normal; the rows are the rules above it.
 *
 * A cell is a quoted literal, so `kadaster.nl` matches exactly that domain;
 * an empty field is the wildcard `-`. Sender address and domain are compared
 * in lower case, as the intake stores them.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */

export const FLOW_NAME = 'Route incoming tickets'
export const FLOW_APP = 'pipelinq'
export const TABLE_NODE_TYPE = 'openregister.decision-table'
export const CATCH_ALL_ID = 'everything-else'
export const INPUTS = ['channel', 'fromAddress', 'fromDomain', 'mailbox', 'clientSegment']
export const OUTPUTS = ['category', 'priority']
export const CHANNELS = ['email', 'whatsapp', 'sms', 'facebook', 'instagram']
export const PRIORITIES = ['low', 'normal', 'high', 'urgent']

const LOWER_CASE = ['fromAddress', 'fromDomain']

/**
 * The catch-all rule the table always ends in.
 *
 * @return {object} The rule.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function catchAllRule() {
	return {
		id: CATCH_ALL_ID,
		description: 'Everything else: no category, normal priority.',
		inputEntries: INPUTS.map(() => '-'),
		outputEntries: ['', 'normal'],
	}
}

/**
 * An empty row, as "Add rule" starts it.
 *
 * @return {object} The row.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function emptyRow() {
	return {
		id: '',
		channel: '',
		fromAddress: '',
		fromDomain: '',
		mailbox: '',
		clientSegment: '',
		category: '',
		priority: 'normal',
	}
}

/**
 * A cell as the value it matches; '' for the wildcard.
 *
 * @param {string|null|undefined} cell The table cell.
 * @return {string} The value.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function valueFromCell(cell) {
	const text = String(cell ?? '').trim()
	if (text === '' || text === '-') {
		return ''
	}
	if (text.length >= 2 && text.startsWith('"') && text.endsWith('"')) {
		return text.slice(1, -1)
	}
	return text
}

/**
 * A value as the cell that matches exactly it; the wildcard for ''.
 *
 * @param {string} name The input it is for.
 * @param {string|null|undefined} value The value.
 * @return {string} The cell.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function cellFromValue(name, value) {
	let text = String(value ?? '').trim().replaceAll('"', '')
	if (LOWER_CASE.includes(name)) {
		text = text.toLowerCase()
	}
	return text === '' ? '-' : `"${text}"`
}

/**
 * The rows of a table: every rule but the catch-all, in order.
 *
 * @param {object} table The decision table.
 * @return {Array<object>} The rows.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function rowsFromTable(table) {
	const inputs = Array.isArray(table?.inputs) ? table.inputs.map((input) => input?.name) : INPUTS
	const outputs = Array.isArray(table?.outputs) ? table.outputs.map((output) => output?.name) : OUTPUTS
	const rules = Array.isArray(table?.rules) ? table.rules : []

	return rules
		.filter((rule) => rule?.id !== CATCH_ALL_ID)
		.map((rule) => {
			const row = emptyRow()
			row.id = String(rule?.id ?? '')
			INPUTS.forEach((name) => {
				const at = inputs.indexOf(name)
				row[name] = at === -1 ? '' : valueFromCell(rule?.inputEntries?.[at])
			})
			OUTPUTS.forEach((name) => {
				const at = outputs.indexOf(name)
				row[name] = at === -1 ? '' : String(rule?.outputEntries?.[at] ?? '')
			})
			if (row.priority === '') {
				row.priority = 'normal'
			}
			return row
		})
}

/**
 * The table of a list of rows: the rows in order, then the catch-all.
 *
 * @param {Array<object>} rows The rows.
 * @param {object} base The table the rows came from; its other keys are kept.
 * @return {object} The table.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function tableFromRows(rows, base = {}) {
	const used = new Set()
	const rules = rows.map((row, index) => {
		let id = String(row.id ?? '').trim()
		if (id === '' || id === CATCH_ALL_ID || used.has(id)) {
			id = `rule-${index + 1}`
		}
		while (used.has(id)) {
			id = `${id}-${index + 1}`
		}
		used.add(id)
		return {
			id,
			inputEntries: INPUTS.map((name) => cellFromValue(name, row[name])),
			outputEntries: [String(row.category ?? '').trim(), PRIORITIES.includes(row.priority) ? row.priority : 'normal'],
		}
	})

	return {
		...base,
		hitPolicy: 'FIRST',
		inputs: INPUTS.map((name) => ({ name, type: 'string' })),
		outputs: OUTPUTS.map((name) => ({ name })),
		rules: [...rules, catchAllRule()],
	}
}

/**
 * What is wrong with the rows before they are saved, one line per row.
 *
 * A row without a condition matches every ticket and so hides every row
 * under it; a row without a category routes nowhere.
 *
 * @param {Array<object>} rows The rows.
 * @return {Array<{row: number, problem: string}>} The problems; none when the rows can be saved.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function rowProblems(rows) {
	const problems = []
	rows.forEach((row, index) => {
		if (INPUTS.every((name) => String(row[name] ?? '').trim() === '')) {
			problems.push({ row: index + 1, problem: 'no-condition' })
		}
		if (String(row.category ?? '').trim() === '') {
			problems.push({ row: index + 1, problem: 'no-category' })
		}
	})
	return problems
}

/**
 * The shipped routing flow in a list of flows; null when it is not there.
 *
 * @param {Array<object>} flows The flows.
 * @return {object|null} The flow.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function findRoutingFlow(flows) {
	return (Array.isArray(flows) ? flows : []).find((flow) => flow?.name === FLOW_NAME && flow?.app === FLOW_APP) ?? null
}

/**
 * The flow's decision table; null when it has no table step.
 *
 * @param {object} flow The flow.
 * @return {object|null} The table.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function tableOf(flow) {
	const node = (Array.isArray(flow?.nodes) ? flow.nodes : []).find((candidate) => candidate?.type === TABLE_NODE_TYPE)
	return node?.config?.table ?? null
}

/**
 * The flow's nodes with the table step's table replaced by the rows.
 *
 * @param {object} flow The flow.
 * @param {Array<object>} rows The rows.
 * @return {Array<object>} The nodes.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function nodesWithRows(flow, rows) {
	return (Array.isArray(flow?.nodes) ? flow.nodes : []).map((node) => {
		if (node?.type !== TABLE_NODE_TYPE) {
			return node
		}
		return { ...node, config: { ...node.config, table: tableFromRows(rows, node.config?.table ?? {}) } }
	})
}

/**
 * Move one row up or down; the list is returned unchanged at its ends.
 *
 * @param {Array<object>} rows The rows.
 * @param {number} index The row to move.
 * @param {number} step -1 for up, 1 for down.
 * @return {Array<object>} The rows in their new order.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
export function moveRow(rows, index, step) {
	const target = index + step
	if (index < 0 || index >= rows.length || target < 0 || target >= rows.length) {
		return rows
	}
	const next = [...rows]
	const [row] = next.splice(index, 1)
	next.splice(target, 0, row)
	return next
}
