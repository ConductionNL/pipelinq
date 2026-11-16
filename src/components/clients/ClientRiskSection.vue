<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The client's churn risk on ClientDetail (clients-history-summary-and-risk,
design D4): the level as a badge and every reason behind it, each with the
points it adds and links to the records it names. The level is written by
ClientRiskService; this panel only reads `churnRisk` off the client.

@spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
-->
<template>
	<div class="client-risk" data-testid="client-risk">
		<p v-if="!hasRisk" class="client-risk__empty">
			{{ t('pipelinq', 'The risk of this client has not been computed yet.') }}
		</p>
		<template v-else>
			<p class="client-risk__level">
				<span
					class="client-risk__badge"
					:class="['client-risk__badge--' + level]"
					data-testid="client-risk-badge">
					{{ levelLabel }}
				</span>
				<span class="client-risk__meta">
					{{ t('pipelinq', 'Score {score}, computed {date}', { score: risk.score || 0, date: computedLabel }) }}
				</span>
			</p>
			<p v-if="reasons.length === 0" class="client-risk__empty">
				{{ t('pipelinq', 'No warning signals.') }}
			</p>
			<ul v-else class="client-risk__reasons">
				<li v-for="(reason, index) in reasons" :key="index" data-testid="client-risk-reason">
					<span>{{ signalLabel(reason.signal) }}</span>
					<span class="client-risk__points">+{{ reason.points }}</span>
					<span v-if="recordLinks(reason).length > 0" class="client-risk__records">
						<router-link
							v-for="link in recordLinks(reason)"
							:key="link.id"
							:to="link.to">
							{{ t('pipelinq', 'Open record') }}
						</router-link>
					</span>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
/**
 * The route that shows a record of a signal, by signal.
 */
const SIGNAL_ROUTES = {
	'contract-expiring': 'ContractDetail',
	'contract-churned': 'ContractDetail',
	'open-complaints': 'TicketDetail',
	'sla-breached': 'TicketDetail',
}

export default {
	name: 'ClientRiskSection',

	props: {
		/** The client's `churnRisk` (resolved from `@object.churnRisk`). */
		risk: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * Whether a level has been computed.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
		 */
		hasRisk() {
			return Boolean(this.risk && ['low', 'medium', 'high'].includes(this.risk.level))
		},

		/**
		 * The level.
		 *
		 * @return {string}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
		 */
		level() {
			return this.hasRisk ? this.risk.level : ''
		},

		/**
		 * The level in words.
		 *
		 * @return {string}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
		 */
		levelLabel() {
			return {
				low: this.t('pipelinq', 'Low risk'),
				medium: this.t('pipelinq', 'Medium risk'),
				high: this.t('pipelinq', 'High risk'),
			}[this.level] || ''
		},

		/**
		 * The reasons, heaviest first.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
		 */
		reasons() {
			const list = this.hasRisk && Array.isArray(this.risk.reasons) ? this.risk.reasons : []
			return [...list].sort((left, right) => (right.points || 0) - (left.points || 0))
		},

		/**
		 * When the level was computed, as a local date.
		 *
		 * @return {string}
		 * @spec exclude display formatter: an ISO date to a local string
		 */
		computedLabel() {
			const date = new Date(this.risk?.computedAt || '')
			return Number.isNaN(date.getTime()) ? '-' : date.toLocaleDateString()
		},
	},

	methods: {
		/**
		 * A signal in words.
		 *
		 * @param {string} signal The signal.
		 * @return {string}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
		 */
		signalLabel(signal) {
			return {
				'contract-expiring': this.t('pipelinq', 'Contract expiring'),
				'contract-churned': this.t('pipelinq', 'Churned contract'),
				detractor: this.t('pipelinq', 'Unhappy survey answer'),
				'open-complaints': this.t('pipelinq', 'Open complaints'),
				'sla-breached': this.t('pipelinq', 'Term passed'),
				'no-recent-contact': this.t('pipelinq', 'No recent contact'),
			}[signal] || signal
		},

		/**
		 * Links to the records behind a reason, where the app has a page for them.
		 *
		 * @param {object} reason The reason.
		 * @return {Array<{id: string, to: object}>}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-1.4
		 */
		recordLinks(reason) {
			const name = SIGNAL_ROUTES[reason.signal]
			if (!name || !this.hasRoute(name) || !Array.isArray(reason.records)) {
				return []
			}
			return reason.records
				.filter((id) => typeof id === 'string' && id !== '')
				.map((id) => ({ id, to: { name, params: { id } } }))
		},

		/**
		 * Whether the router knows a route.
		 *
		 * @param {string} name The route name.
		 * @return {boolean}
		 * @spec exclude router lookup plumbing, no behaviour of its own
		 */
		hasRoute(name) {
			const router = this.$router
			if (!router || typeof router.getRoutes !== 'function') {
				return false
			}
			return router.getRoutes().some((route) => route.name === name)
		},
	},
}
</script>

<style scoped>
.client-risk__level {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
}

.client-risk__badge {
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-weight: bold;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.client-risk__badge--high {
	background-color: var(--color-error);
	color: var(--color-primary-element-text);
}

.client-risk__badge--medium {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.client-risk__badge--low {
	background-color: var(--color-success);
	color: var(--color-primary-element-text);
}

.client-risk__meta,
.client-risk__empty {
	color: var(--color-text-maxcontrast);
}

.client-risk__reasons {
	margin: 0;
	padding-inline-start: 20px;
}

.client-risk__reasons li {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.client-risk__points {
	color: var(--color-text-maxcontrast);
}

.client-risk__records {
	display: inline-flex;
	gap: 8px;
}
</style>
