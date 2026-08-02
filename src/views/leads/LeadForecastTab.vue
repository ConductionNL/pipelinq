<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="lead-forecast-tab">
		<label class="lead-forecast-tab__label" for="forecast-category-select">
			{{ t('pipelinq', 'Forecast category') }}
		</label>
		<div class="lead-forecast-tab__row">
			<NcSelect
				v-model="selected"
				input-id="forecast-category-select"
				:input-label="t('pipelinq', 'Forecast category')"
				:options="categoryOptions"
				label="label"
				:disabled="locked"
				:clearable="false"
				@update:model-value="onChange" />
			<span v-if="locked"
				class="lead-forecast-tab__lock"
				:title="t('pipelinq', 'Reopen the deal to change the forecast category')">
				🔒
			</span>
		</div>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<div v-if="justification" class="lead-forecast-tab__justification">
			<strong>{{ t('pipelinq', 'Commit justification') }}:</strong>
			<p>{{ justification }}</p>
		</div>

		<section v-if="history.length" class="lead-forecast-tab__history">
			<h4>{{ t('pipelinq', 'Category history') }}</h4>
			<table>
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'When') }}</th>
						<th>{{ t('pipelinq', 'From') }}</th>
						<th>{{ t('pipelinq', 'To') }}</th>
						<th>{{ t('pipelinq', 'By') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in history" :key="entry.timestamp">
						<td>{{ entry.timestamp }}</td>
						<td>{{ categoryLabel(entry.from) }}</td>
						<td>{{ categoryLabel(entry.to) }}</td>
						<td>{{ entry.by }}</td>
					</tr>
				</tbody>
			</table>
		</section>

		<CommitJustificationModal
			v-if="showJustification"
			:initial-reason="justification"
			@close="cancelJustification"
			@save="confirmJustification" />
	</div>
</template>

<script>
import { NcNoteCard, NcSelect } from '@nextcloud/vue'
import CommitJustificationModal from '../../modals/CommitJustificationModal.vue'

const CLOSED = ['closed_won', 'closed_lost']
const COMMIT_THRESHOLD = 50000

export default {
	name: 'LeadForecastTab',
	components: { NcNoteCard, NcSelect, CommitJustificationModal },
	props: {
		// The lead object provided by the detail renderer slot.
		objectData: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['update'],
	data() {
		const current = this.objectData?.forecast_category || 'pipeline'
		return {
			selected: this.toOption(current),
			pending: null,
			justification: this.objectData?.commit_justification || '',
			showJustification: false,
			errorMessage: '',
			history: this.objectData?.forecast_category_history || [],
		}
	},
	computed: {
		categoryOptions() {
			return [
				{ id: 'commit', label: t('pipelinq', 'Commit') },
				{ id: 'best_case', label: t('pipelinq', 'Best-case') },
				{ id: 'pipeline', label: t('pipelinq', 'Pipeline') },
				{ id: 'omitted', label: t('pipelinq', 'Omitted') },
				{ id: 'closed_won', label: t('pipelinq', 'Closed Won') },
				{ id: 'closed_lost', label: t('pipelinq', 'Closed Lost') },
			]
		},
		locked() {
			return CLOSED.includes(this.objectData?.forecast_category)
		},
		dealValue() {
			return Number(this.objectData?.value || 0)
		},
	},
	methods: {
		toOption(id) {
			return { id, label: this.categoryLabel(id) }
		},
		categoryLabel(id) {
			const map = {
				commit: t('pipelinq', 'Commit'),
				best_case: t('pipelinq', 'Best-case'),
				pipeline: t('pipelinq', 'Pipeline'),
				omitted: t('pipelinq', 'Omitted'),
				closed_won: t('pipelinq', 'Closed Won'),
				closed_lost: t('pipelinq', 'Closed Lost'),
			}
			return map[id] || id
		},
		onChange(option) {
			this.errorMessage = ''
			const id = option?.id
			if (!id) {
				return
			}
			if (id === 'commit' && this.dealValue > COMMIT_THRESHOLD
				&& this.justification.trim().length < 10) {
				this.pending = id
				this.showJustification = true
				return
			}
			this.persist(id, this.justification)
		},
		confirmJustification(reason) {
			this.justification = reason
			this.showJustification = false
			this.persist(this.pending || 'commit', reason)
			this.pending = null
		},
		cancelJustification() {
			this.showJustification = false
			this.pending = null
			// Revert the selector to the persisted value.
			this.selected = this.toOption(this.objectData?.forecast_category || 'pipeline')
		},
		persist(category, justification) {
			this.$emit('update', {
				forecast_category: category,
				commit_justification: justification,
			})
		},
	},
}
</script>

<style scoped>
.lead-forecast-tab { padding: 12px 0; }
.lead-forecast-tab__row { display: flex; align-items: center; gap: 8px; }
.lead-forecast-tab__lock { font-size: 1.2em; cursor: help; }
.lead-forecast-tab__justification { margin-top: 12px; }
.lead-forecast-tab__history { margin-top: 16px; }
.lead-forecast-tab__history table { width: 100%; border-collapse: collapse; }
.lead-forecast-tab__history th, .lead-forecast-tab__history td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--color-border); }
</style>
