<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="tenders-card">
		<table class="tenders-card__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Tender Type') }}</th>
					<th class="num">
						{{ t('pipelinq', 'Amount') }}
					</th>
					<th>{{ t('pipelinq', 'GL Account') }}</th>
					<th>{{ t('pipelinq', 'Reference') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="tender in tenders" :key="tender.id">
					<td>{{ tenderTypeName(tender) }}</td>
					<td class="num">
						{{ formatEur(tender.amount) }}
					</td>
					<td>{{ tender.glAccount || '-' }}</td>
					<td>{{ tender.reference || '-' }}</td>
					<td class="num">
						<NcButton v-if="canRemove"
							type="tertiary"
							:aria-label="t('pipelinq', 'Remove Tender')"
							:title="t('pipelinq', 'Remove Tender')"
							@click="$emit('remove', tender)">
							{{ t('pipelinq', 'Remove') }}
						</NcButton>
						<span v-else class="tenders-card__locked" :title="removeDisabledHint">
							{{ t('pipelinq', 'Settled') }}
						</span>
					</td>
				</tr>
				<tr v-if="tenders.length === 0">
					<td colspan="5" class="empty">
						{{ t('pipelinq', 'No tenders yet.') }}
					</td>
				</tr>
			</tbody>
		</table>

		<div class="tenders-card__summary">
			<span>{{ t('pipelinq', 'Tendered') }}: <strong>{{ formatEur(tenderSum) }}</strong></span>
			<span>{{ t('pipelinq', 'Total') }}: <strong>{{ formatEur(transactionTotal) }}</strong></span>
			<span v-if="changeDue > 0" class="tenders-card__change">
				{{ t('pipelinq', 'Change due: {change} EUR', { change: changeDue.toFixed(2) }) }}
			</span>
		</div>

		<NcNoteCard v-if="statusType === 'success'" type="success">
			{{ t('pipelinq', 'Payment complete') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="statusType === 'warning'" type="warning">
			{{ t('pipelinq', 'Underpayment: {diff} EUR', { diff: Math.abs(variance).toFixed(2) }) }}
		</NcNoteCard>
		<NcNoteCard v-else-if="statusType === 'error'" type="error">
			{{ t('pipelinq', 'Overpayment without change tender') }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { formatEur } from '../../services/posTotals.js'

export default {
	name: 'TendersCard',
	components: {
		NcButton,
		NcNoteCard,
	},
	props: {
		tenders: {
			type: Array,
			default: () => [],
		},
		tenderTypes: {
			type: Array,
			default: () => [],
		},
		reconciliation: {
			type: Object,
			default: () => ({}),
		},
		transactionTotal: {
			type: Number,
			default: 0,
		},
		canRemove: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['remove'],
	computed: {
		/**
		 * Sum of the tendered amounts (from the server reconciliation when present).
		 *
		 * @return {number} The tender sum.
		 */
		tenderSum() {
			if (typeof this.reconciliation.tenderSum === 'number') {
				return this.reconciliation.tenderSum
			}
			return this.tenders.reduce((sum, tender) => sum + (parseFloat(tender.amount) || 0), 0)
		},
		/**
		 * The server-computed variance (total - tenderSum).
		 *
		 * @return {number} The variance.
		 */
		variance() {
			return typeof this.reconciliation.variance === 'number' ? this.reconciliation.variance : 0
		},
		/**
		 * The server-computed change due.
		 *
		 * @return {number} The change.
		 */
		changeDue() {
			return typeof this.reconciliation.changeDue === 'number' ? this.reconciliation.changeDue : 0
		},
		/**
		 * Hint shown on the disabled remove control for a settled transaction.
		 *
		 * @return {string} The hint.
		 */
		removeDisabledHint() {
			return t('pipelinq', 'Cannot remove tenders from a settled transaction')
		},
		/**
		 * The reconciliation status to surface: success / warning / error / none.
		 *
		 * @return {string} The status type.
		 */
		statusType() {
			if (this.tenders.length === 0) {
				return 'none'
			}
			if (this.reconciliation.reconciled === true) {
				return 'success'
			}
			if (this.variance > 0) {
				return 'warning'
			}
			if (this.variance < 0) {
				return 'error'
			}
			return 'none'
		},
	},
	methods: {
		formatEur,
		/**
		 * Resolve a tender's display type name from the tender-type list, falling
		 * back to the raw type id.
		 *
		 * @param {object} tender The tender row.
		 * @return {string} The display name.
		 */
		tenderTypeName(tender) {
			const match = this.tenderTypes.find(type => (type.id || type.uuid) === tender.tenderType)
			return match ? match.name : (tender.tenderType || '-')
		},
	},
}
</script>

<style scoped>
.tenders-card__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.tenders-card__table th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.tenders-card__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.num {
	text-align: right;
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.tenders-card__summary {
	display: flex;
	gap: 24px;
	flex-wrap: wrap;
	padding: 4px 8px;
}

.tenders-card__change {
	color: var(--color-success);
	font-weight: bold;
}

.tenders-card__locked {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
