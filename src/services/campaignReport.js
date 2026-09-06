// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The campaign report, as the page reads it.
 *
 * Pure and dependency-free, the way `articleStatus.js` and
 * `subscriptionState.js` are, so the shaping the report page does can be
 * unit-tested offline. Three jobs:
 *
 *   • turn the per-channel rows into table rows, keeping "not recorded"
 *     distinct from zero,
 *   • turn one attribution model into rows ordered by attributed value,
 *   • label a closing basis so a reader can see which of a campaign's
 *     euros are money shillinq collected and which are a forecast.
 *
 * Nothing here fetches. The page hands it the one response the report
 * endpoint returned.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */

/** The attribution models the report computes, in the order they are offered. */
export const ATTRIBUTION_MODELS = ['first', 'last', 'linear']

/** What a closing basis is called, and how it reads. */
const BASES = {
	paid_invoice: {
		label: 'Paid invoice',
		description: 'Closed on money shillinq recorded as collected.',
		color: 'var(--color-success)',
	},
	won_lead: {
		label: 'Won lead',
		description: 'Closed on the lead value, which is a forecast.',
		color: 'var(--color-warning)',
	},
	open: {
		label: 'Open',
		description: 'Not closed, so it contributes nothing.',
		color: 'var(--color-text-maxcontrast)',
	},
}

/** What a model is called in the picker. */
const MODEL_LABELS = {
	first: 'First touch',
	last: 'Last touch',
	linear: 'Linear',
}

/**
 * The label and colour for one closing basis.
 *
 * @param {string} basis The basis the report reported.
 * @return {{label: string, description: string, color: string}} The chip.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */
export function chipForBasis(basis) {
	return (
		BASES[basis] || {
			label: 'Unknown',
			description: 'The report named a basis this page does not know.',
			color: 'var(--color-text-maxcontrast)',
		}
	)
}

/**
 * The label for one attribution model.
 *
 * @param {string} model One of first, last, linear.
 * @return {string} The label.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */
export function labelForModel(model) {
	return MODEL_LABELS[model] || model
}

/**
 * The channel table, one row per channel.
 *
 * A reach the report left null stays null and renders as "not recorded".
 * Turning it into 0 here would claim nobody was reached, which is a
 * different and wrong statement.
 *
 * @param {object} report The report response.
 * @return {Array<object>} The rows.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
export function channelRows(report) {
	const channels = report?.channels || []
	return channels.map((row) => ({
		channel: row.channel,
		reach: row.reach === null || row.reach === undefined ? null : row.reach,
		opened: row.opened || 0,
		clicks: row.click || 0,
		visits: row.visit || 0,
		submissions: row.submit || 0,
		replies: row.reply || 0,
	}))
}

/**
 * The rows for one attribution model, biggest share first.
 *
 * @param {object} report The report response.
 * @param {string} model One of first, last, linear.
 * @return {Array<{channel: string, value: number, share: number}>} The rows.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
 */
export function modelRows(report, model) {
	const byChannel = report?.models?.[model]?.byChannel || {}
	const total = report?.models?.[model]?.total || 0
	return Object.entries(byChannel)
		.map(([channel, value]) => ({
			channel,
			value,
			share: total > 0 ? value / total : 0,
		}))
		.sort(
			(left, right) =>
				right.value - left.value
				|| left.channel.localeCompare(right.channel),
		)
}

/**
 * The lead rows, with the closing basis already labelled.
 *
 * @param {object} report The report response.
 * @return {Array<object>} The rows.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
 */
export function leadRows(report) {
	const leads = report?.leads || []
	return leads.map((lead) => ({
		leadId: lead.leadId,
		title: lead.title || lead.leadId,
		basis: lead.basis,
		chip: chipForBasis(lead.basis),
		value: lead.value || 0,
		invoiceCount: (lead.invoiceIds || []).length,
	}))
}

/**
 * The headline numbers, in the order the page shows them.
 *
 * `cost` is null when nothing was recorded, and the page renders that as
 * "not recorded" rather than as a zero-euro campaign.
 *
 * @param {object} report The report response.
 * @return {{leads: number, submissions: number, clicks: number, attributedValue: number, cost: (number|null), budget: (number|null)}}
 *         The tiles.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
export function summaryTiles(report) {
	const engagement = report?.engagement || {}
	const cost = report?.cost || {}
	return {
		leads: report?.totals?.leads || 0,
		submissions: engagement.submit || 0,
		clicks: engagement.click || 0,
		attributedValue: report?.totals?.attributedValue || 0,
		cost: cost.totalEur === undefined ? null : cost.totalEur,
		budget: cost.budgetEur === undefined ? null : cost.budgetEur,
	}
}
