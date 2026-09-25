<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for PosRefundDetail: the "Returned items" table.
  -
  - This is a CROSS-SCHEMA JOIN: each posRefundLine row is enriched with its
  - original posTransactionLine (description and original quantity, through
  - the line's `originalLine` pointer). An object-list renders one schema and
  - cannot join the refund line to its original-transaction line.
  -
  - Re-reads the lines on `cn:page:refresh`.
  -->
<template>
	<CnWidgetWrapper
		class="pos-refund-items"
		:title="title || t('pipelinq', 'Returned items')"
		titleIconPosition="left"
		:showActions="false"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="PackageVariant" :size="20" />
		</template>

		<NcLoadingIcon v-if="loading" :size="24" />
		<table v-else class="pos-refund-items__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Description') }}</th>
					<th scope="col" class="num">
						{{ t('pipelinq', 'Original qty') }}
					</th>
					<th scope="col" class="num">
						{{ t('pipelinq', 'Returned qty') }}
					</th>
					<th scope="col">{{ t('pipelinq', 'Reason') }}</th>
					<th scope="col">{{ t('pipelinq', 'Restock') }}</th>
					<th scope="col" class="num">
						{{ t('pipelinq', 'Refund total') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in lineRows" :key="row.id">
					<td>{{ row.description }}</td>
					<td class="num">
						{{ row.originalQuantity }}
					</td>
					<td class="num">
						{{ row.returnedQuantity }}
					</td>
					<td>{{ reasonLabel(row.returnReason) }}</td>
					<td>{{ row.restock ? t('pipelinq', 'Yes') : t('pipelinq', 'No') }}</td>
					<td class="num">
						{{ formatEur(row.lineTotal) }}
					</td>
				</tr>
				<tr v-if="lineRows.length === 0">
					<td colspan="6" class="pos-refund-items__empty">
						{{ t('pipelinq', 'No returned items.') }}
					</td>
				</tr>
			</tbody>
		</table>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcLoadingIcon } from '@nextcloud/vue'
import { fetchOriginalLines, fetchRefundLines } from '../../services/posRefundLines.js'
import { formatEur } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRefundItemsWidget',
	components: { CnIcon, CnWidgetWrapper, NcLoadingIcon },
	// The host also hands over register / schema / store and the widget
	// content; none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/** The refund, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The refund id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			lines: [],
			originalLines: [],
			reasons: [],
			loading: false,
		}
	},

	computed: {
		refundId() {
			return this.objectId || this.objectData?.id || ''
		},

		originalTransaction() {
			return this.objectData?.originalTransaction || ''
		},

		/**
		 * Display rows joining each refund line with its original transaction line.
		 *
		 * @return {Array<object>} The rows.
		 */
		lineRows() {
			return this.lines.map((line) => {
				const original = this.originalLines.find((o) => o.id === line.originalLine) || {}
				return {
					id: line.id,
					description: original.description || '-',
					originalQuantity: original.quantity ?? '-',
					returnedQuantity: line.returnedQuantity,
					returnReason: line.returnReason,
					restock: line.restock ?? true,
					lineTotal: line.lineTotal || 0,
				}
			})
		},
	},

	watch: {
		// The original transaction arrives with the refund, which can land
		// after the id; either one changing means a different join.
		refundId() {
			this.load()
		},

		originalTransaction() {
			this.load()
		},
	},

	created() {
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	mounted() {
		this.load()
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		formatEur,

		/**
		 * Resolve a refundReason id to its label.
		 *
		 * @param {string} id The reason id.
		 * @return {string} The label.
		 */
		reasonLabel(id) {
			const reason = this.reasons.find((r) => r.id === id)
			return reason ? reason.label || reason.code : id || '-'
		},

		/**
		 * Load the refund's lines, the refund reasons and the original
		 * transaction's lines.
		 *
		 * @param {object} [options] Options.
		 * @param {boolean} [options.silent] Keep the table on screen while
		 *   reloading.
		 * @return {Promise<void>}
		 */
		async load({ silent = false } = {}) {
			if (!this.refundId) {
				return
			}
			this.loading = !silent
			try {
				const store = useObjectStore()
				this.lines = await fetchRefundLines(store, this.refundId)
				await store.fetchCollection('refundReason', { _limit: 100 })
				this.reasons = store.getCollection('refundReason')?.results || []
				this.originalLines = await fetchOriginalLines(store, this.originalTransaction)
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Could not load refund.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} [payload] The refresh payload.
		 */
		onPageRefresh(payload) {
			const done = this.load({ silent: true })
			payload?.waitUntil?.(done)
		},
	},
}
</script>

<style scoped>
.pos-refund-items {
	height: 100%;
	display: flex;
	flex-direction: column;
}

.pos-refund-items__table {
	width: 100%;
	border-collapse: collapse;
}

.pos-refund-items__table th {
	text-align: start;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-refund-items__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-refund-items__table .num {
	text-align: end;
}

.pos-refund-items__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
