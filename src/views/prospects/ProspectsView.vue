<template>
	<div class="prospects-view">
		<div class="prospects-view__header">
			<h2>{{ t('pipelinq', 'Prospects') }}</h2>
			<NcButton
				variant="tertiary"
				:disabled="prospectStore.loading"
				:aria-label="t('pipelinq', 'Refresh prospects')"
				@click="refresh">
				<template #icon>
					<Refresh
						:size="20"
						:class="{ 'icon-spinning': prospectStore.loading }" />
				</template>
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="prospectStore.loading" :size="32" />

		<!-- No ICP configured -->
		<NcEmptyContent
			v-else-if="prospectStore.error && prospectStore.error.includes('ICP')"
			:name="t('pipelinq', 'No Ideal Customer Profile configured')"
			:description="
				t(
					'pipelinq',
					'Configure your Ideal Customer Profile in admin settings to discover prospects.',
				)
			">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>

		<!-- Error -->
		<NcEmptyContent
			v-else-if="prospectStore.error"
			:name="t('pipelinq', 'Could not load prospects')"
			:description="prospectStore.error">
			<template #icon>
				<AlertCircle :size="20" />
			</template>
		</NcEmptyContent>

		<!-- Empty -->
		<NcEmptyContent
			v-else-if="sortedProspects.length === 0"
			:name="t('pipelinq', 'No prospects found')"
			:description="
				t(
					'pipelinq',
					'No companies currently match your Ideal Customer Profile.',
				)
			">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>

		<!-- Scored prospect table -->
		<table v-else class="prospects-view__table">
			<thead>
				<tr>
					<th scope="col" class="sortable" @click="setSort('fitScore')">
						{{ t('pipelinq', 'Score') }}{{ sortIndicator('fitScore') }}
					</th>
					<th scope="col" class="sortable" @click="setSort('tradeName')">
						{{ t('pipelinq', 'Company')
						}}{{ sortIndicator('tradeName') }}
					</th>
					<th scope="col">{{ t('pipelinq', 'Industry') }}</th>
					<th
						scope="col"
						class="sortable"
						@click="setSort('employeeCount')">
						{{ t('pipelinq', 'Employees')
						}}{{ sortIndicator('employeeCount') }}
					</th>
					<th scope="col">{{ t('pipelinq', 'Location') }}</th>
					<th scope="col">{{ t('pipelinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="p in sortedProspects"
					:key="p.kvkNumber"
					:data-testid="`prospect-row-${p.kvkNumber}`">
					<td>
						<span
							class="prospects-view__score"
							:class="scoreClass(p.fitScore)"
							>{{ p.fitScore }}%</span
						>
					</td>
					<td>{{ p.tradeName }}</td>
					<td>{{ p.sbiDescription || '—' }}</td>
					<td>{{ p.employeeCount || '—' }}</td>
					<td>{{ p.address && p.address.city ? p.address.city : '—' }}</td>
					<td>
						<NcButton
							variant="tertiary"
							:disabled="convertingKvk === p.kvkNumber"
							@click="convertToLead(p)">
							{{ t('pipelinq', 'Convert to lead') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
// Full-page expansion of ProspectWidget (refactor-pipelinq-ia-alignment): a
// scored-prospect list with sortable columns and a per-row "Convert to lead"
// action, backed by the shared prospect Pinia store.
//
// @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useProspectStore } from '../../store/modules/prospect.js'

export default {
	name: 'ProspectsView',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
		Magnify,
		AlertCircle,
	},

	/**
	 * Expose the prospect Pinia store to the component.
	 *
	 * @return {object} The setup bindings.
	 * @spec exclude Pinia store wiring — no business logic
	 */
	setup() {
		return { prospectStore: useProspectStore() }
	},

	data() {
		return {
			sortKey: 'fitScore',
			sortAsc: false,
			convertingKvk: null,
		}
	},

	computed: {
		/**
		 * Prospects sorted by the active sort column/direction.
		 *
		 * @return {Array<object>} The sorted prospect list.
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		sortedProspects() {
			const list = [...(this.prospectStore.prospects || [])]
			const key = this.sortKey
			const dir = this.sortAsc ? 1 : -1
			return list.sort((a, b) => {
				const av = a[key] ?? ''
				const bv = b[key] ?? ''
				if (typeof av === 'number' && typeof bv === 'number')
					return (av - bv) * dir
				return String(av).localeCompare(String(bv)) * dir
			})
		},
	},

	mounted() {
		this.prospectStore.fetchProspects()
	},

	methods: {
		/**
		 * Force a fresh prospect fetch (bypass cache).
		 *
		 * @spec exclude trivial store passthrough — no business logic
		 */
		refresh() {
			this.prospectStore.fetchProspects(true)
		},

		/**
		 * Toggle/select the active sort column.
		 *
		 * @param {string} key - The column key.
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		setSort(key) {
			if (this.sortKey === key) {
				this.sortAsc = !this.sortAsc
			} else {
				this.sortKey = key
				this.sortAsc = false
			}
		},

		/**
		 * The sort arrow for a column header.
		 *
		 * @param {string} key - The column key.
		 * @return {string} The indicator glyph.
		 * @spec exclude presentational sort-arrow helper — no business logic
		 */
		sortIndicator(key) {
			if (this.sortKey !== key) return ''
			return this.sortAsc ? ' ▲' : ' ▼'
		},

		/**
		 * Map a fit score to a CSS severity class.
		 *
		 * @param {number} score - The prospect fit score (0-100).
		 * @return {string} The CSS class.
		 * @spec exclude presentational score-band helper — no business logic
		 */
		scoreClass(score) {
			const s = score || 0
			if (s > 70) return 'score--high'
			if (s >= 40) return 'score--medium'
			return 'score--low'
		},

		/**
		 * Convert a prospect into a CRM lead via the prospect store.
		 *
		 * @param {object} prospect - The prospect record.
		 * @return {Promise<void>}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		async convertToLead(prospect) {
			this.convertingKvk = prospect.kvkNumber
			try {
				await this.prospectStore.createLeadFromProspect(prospect)
			} finally {
				this.convertingKvk = null
			}
		},
	},
}
</script>

<style scoped>
.prospects-view {
	padding: 16px;
}

.prospects-view__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.prospects-view__table {
	width: 100%;
	border-collapse: collapse;
}

.prospects-view__table th,
.prospects-view__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.prospects-view__table th.sortable {
	cursor: pointer;
	user-select: none;
}

.prospects-view__score {
	font-weight: bold;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
}

.score--high {
	color: var(--color-success);
}

.score--medium {
	color: var(--color-warning);
}

.score--low {
	color: var(--color-text-maxcontrast);
}

.icon-spinning {
	animation: rotate 1s linear infinite;
}
@keyframes rotate {
	from {
		transform: rotate(0);
	}
	to {
		transform: rotate(360deg);
	}
}

@media (prefers-reduced-motion: reduce) {
	.icon-spinning {
		animation: none;
	}
}
</style>
