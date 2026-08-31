<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Per-transaction tender entry panel (pos-split-tender REQ-PST-002..005).
  -
  - Shows: list of tenders for the transaction, server-authoritative
  - validation summary (sum / total / remaining / balanced), an Add Tender
  - action that opens AddTenderDialog and a per-row Remove action. Read-only
  - when the transaction is `settled`.
  -
  - @spec openspec/changes/pos-split-tender/tasks.md#7.2
  - @spec openspec/changes/pos-split-tender/tasks.md#7.5
  - @spec openspec/changes/pos-split-tender/tasks.md#7.6
  -->
<template>
	<div class="tender-panel">
		<div class="tender-panel__header">
			<h3>{{ t('pipelinq', 'Tenders') }}</h3>
			<NcButton
				v-if="canEdit"
				variant="primary"
				:disabled="loading"
				@click="openAddDialog">
				{{ t('pipelinq', 'Add tender') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<table v-if="tenders.length > 0" class="tender-panel__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Tender type') }}</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Amount') }}
						</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Change') }}
						</th>
						<th scope="col">{{ t('pipelinq', 'GL account') }}</th>
						<th scope="col">{{ t('pipelinq', 'Reference') }}</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="tender in tenders" :key="tenderId(tender)">
						<td>{{ tenderTypeLabel(tender) }}</td>
						<td class="num">
							{{ formatEur(tender.amount) }}
						</td>
						<td class="num">
							<span
								v-if="(tender.change || 0) > 0"
								class="tender-panel__change">
								{{ formatEur(tender.change) }}
							</span>
							<span v-else>-</span>
						</td>
						<td>{{ tender.glAccount || '-' }}</td>
						<td>{{ tender.reference || '-' }}</td>
						<td class="actions">
							<NcButton
								v-if="canEdit"
								variant="error"
								@click="removeTender(tender)">
								{{ t('pipelinq', 'Remove') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="tender-panel__empty">
				{{ t('pipelinq', 'No tenders yet.') }}
			</p>

			<div class="tender-panel__summary" :class="summaryClass">
				<div>
					<strong>{{ t('pipelinq', 'Tender sum:') }}</strong>
					{{ formatEur(validation.tenderSum) }}
				</div>
				<div>
					<strong>{{ t('pipelinq', 'Transaction total:') }}</strong>
					{{ formatEur(validation.transactionTotal) }}
				</div>
				<div v-if="!validation.balanced">
					<strong>{{ remainingLabel }}:</strong>
					{{ formatEur(Math.abs(validation.variance)) }}
				</div>
				<div v-else>
					{{ t('pipelinq', 'Payment balanced — ready to settle.') }}
				</div>
			</div>

			<p v-if="errorMessage" class="tender-panel__error" role="alert">
				{{ errorMessage }}
			</p>
		</template>

		<AddTenderDialog
			v-if="showAdd"
			:transactionId="transactionId"
			:transactionTotal="validation.transactionTotal"
			:remaining="remainingAmount"
			:tenderTypes="activeTenderTypes"
			@close="showAdd = false"
			@added="onTenderAdded" />
		<ConfirmDialog
			v-if="pendingRemoveTender"
			:name="t('pipelinq', 'Remove tender')"
			:message="t('pipelinq', 'Remove this tender?')"
			:confirmLabel="t('pipelinq', 'Remove')"
			@confirm="performRemoveTender"
			@cancel="pendingRemoveTender = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'
import AddTenderDialog from '../../modals/AddTenderDialog.vue'
import { formatEur } from '../../services/posTotals.js'

export default {
	name: 'TenderEntryPanel',
	components: { ConfirmDialog, NcButton, NcLoadingIcon, AddTenderDialog },
	props: {
		transactionId: {
			type: String,
			required: true,
		},

		transactionStatus: {
			type: String,
			default: 'draft',
		},
	},

	emits: ['changed'],
	data() {
		return {
			tenders: [],
			tenderTypes: [],
			validation: {
				tenderSum: 0,
				transactionTotal: 0,
				variance: 0,
				balanced: true,
			},

			loading: false,
			showAdd: false,
			errorMessage: '',
			pendingRemoveTender: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/pos-split-tender/tasks.md#7.2
		 */
		canEdit() {
			return (
				this.transactionStatus !== 'settled'
				&& this.transactionStatus !== 'refunded'
			)
		},

		activeTenderTypes() {
			return this.tenderTypes.filter((type) => type.isActive !== false)
		},

		remainingAmount() {
			return Math.max(0, Number(this.validation?.variance || 0))
		},

		remainingLabel() {
			const variance = Number(this.validation?.variance || 0)
			if (variance > 0) {
				return t('pipelinq', 'Underpayment')
			}
			if (variance < 0) {
				return t('pipelinq', 'Overpayment')
			}
			return t('pipelinq', 'Variance')
		},

		summaryClass() {
			if (this.validation.balanced) {
				return 'tender-panel__summary--ok'
			}
			return Number(this.validation.variance) > 0
				? 'tender-panel__summary--warn'
				: 'tender-panel__summary--error'
		},
	},

	async mounted() {
		await Promise.all([this.loadTenders(), this.loadTenderTypes()])
	},

	methods: {
		formatEur,
		tenderId(tender) {
			if (tender?.['@self']?.id) {
				return tender['@self'].id
			}
			return tender?.id || tender?.uuid || ''
		},

		tenderTypeLabel(tender) {
			const id = tender?.tenderType || ''
			const found = this.tenderTypes.find((t) => this.idOf(t) === id)
			if (found) {
				return found.name || found.code || id
			}
			return id || t('pipelinq', 'Unknown')
		},

		idOf(type) {
			if (type?.['@self']?.id) {
				return type['@self'].id
			}
			return type?.id || type?.uuid || ''
		},

		async loadTenders() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/pos-transactions/{id}/tenders',
					{ id: this.transactionId },
				)
				const response = await axios.get(url)
				this.tenders = response?.data?.results || []
				if (response?.data?.validation) {
					this.validation = response.data.validation
				}
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to load tenders')
			} finally {
				this.loading = false
			}
		},

		async loadTenderTypes() {
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/pos/tender-types?activeOnly=1',
				)
				const response = await axios.get(url)
				this.tenderTypes = response?.data?.results || []
			} catch {
				this.tenderTypes = []
			}
		},

		openAddDialog() {
			if (this.tenderTypes.length === 0) {
				this.loadTenderTypes()
			}
			this.showAdd = true
		},

		async onTenderAdded() {
			this.showAdd = false
			await this.loadTenders()
			this.$emit('changed')
		},

		/**
		 * Open the remove confirmation for a tender.
		 *
		 * @param {object} tender The tender to remove.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#7.2
		 */
		removeTender(tender) {
			if (!this.tenderId(tender)) {
				return
			}
			this.pendingRemoveTender = tender
		},

		/**
		 * Remove the pending tender once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#7.2
		 */
		async performRemoveTender() {
			const tender = this.pendingRemoveTender
			this.pendingRemoveTender = null
			const id = tender ? this.tenderId(tender) : null
			if (!id) {
				return
			}
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/pos-transactions/{tid}/tenders/{id}',
					{ tid: this.transactionId, id },
				)
				await axios.delete(url)
				showSuccess(t('pipelinq', 'Tender removed'))
				await this.loadTenders()
				this.$emit('changed')
			} catch (error) {
				const msg =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to remove tender')
				showError(msg)
			}
		},
	},
}
</script>

<style scoped>
.tender-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.tender-panel__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.tender-panel__header h3 {
	margin: 0;
}

.tender-panel__table {
	width: 100%;
	border-collapse: collapse;
}

.tender-panel__table th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.tender-panel__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.num {
	text-align: right;
}

.actions {
	text-align: right;
}

.tender-panel__change {
	color: var(--color-success);
	font-weight: bold;
}

.tender-panel__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 8px 0;
}

.tender-panel__summary {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.tender-panel__summary--ok {
	background: var(--color-success);
	border: 1px solid var(--color-success-hover);
	color: var(--color-success-text);
}

.tender-panel__summary--warn {
	background: var(--color-warning);
	border: 1px solid var(--color-warning-hover);
	color: var(--color-warning-text);
}

.tender-panel__summary--error {
	background: var(--color-error);
	border: 1px solid var(--color-error-hover);
	color: var(--color-error-text);
}

.tender-panel__error {
	color: var(--color-error);
	margin: 0;
}
</style>
