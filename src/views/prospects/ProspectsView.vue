<template>
	<div class="prospects-view">
		<div class="prospects-view__header">
			<h2>{{ t('pipelinq', 'Prospects') }}</h2>
			<NcButton
				variant="secondary"
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
		<template v-else>
			<table class="prospects-view__table">
				<thead>
					<tr>
						<th
							scope="col"
							class="sortable"
							:aria-sort="ariaSort('fitScore')">
							<button type="button" @click="setSort('fitScore')">
								{{ t('pipelinq', 'Score')
								}}<span aria-hidden="true">{{
									sortIndicator('fitScore')
								}}</span>
							</button>
						</th>
						<th
							scope="col"
							class="sortable"
							:aria-sort="ariaSort('tradeName')">
							<button type="button" @click="setSort('tradeName')">
								{{ t('pipelinq', 'Company')
								}}<span aria-hidden="true">{{
									sortIndicator('tradeName')
								}}</span>
							</button>
						</th>
						<th scope="col">{{ t('pipelinq', 'Industry') }}</th>
						<th
							scope="col"
							class="sortable"
							:aria-sort="ariaSort('employeeCount')">
							<button type="button" @click="setSort('employeeCount')">
								{{ t('pipelinq', 'Employees')
								}}<span aria-hidden="true">{{
									sortIndicator('employeeCount')
								}}</span>
							</button>
						</th>
						<th scope="col">{{ t('pipelinq', 'Location') }}</th>
						<th scope="col">{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="p in pagedProspects"
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
						<td>
							{{ p.address && p.address.city ? p.address.city : '—' }}
						</td>
						<td>
							<NcButton
								variant="secondary"
								:disabled="addingKvk === p.kvkNumber"
								:data-testid="`prospect-add-${p.kvkNumber}`"
								@click="addAsClient(p)">
								{{ t('pipelinq', 'Add as client') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<CnPagination
				:currentPage="currentPage"
				:totalPages="totalPages"
				:totalItems="sortedProspects.length"
				:currentPageSize="pageSize"
				@pageChanged="page = $event"
				@pageSizeChanged="onPageSizeChanged" />
		</template>
	</div>
</template>

<script>
// Full-page expansion of ProspectWidget (refactor-pipelinq-ia-alignment): a
// scored-prospect list with sortable columns and a per-row "Convert to lead"
// action, backed by the shared prospect Pinia store.
//
// @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
import { CnPagination } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { createWithContact } from '../../services/contactSyncApi.js'
import { useProspectStore } from '../../store/modules/prospect.js'

export default {
	name: 'ProspectsView',
	components: {
		CnPagination,
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
			addingKvk: null,
			page: 1,
			pageSize: 20,
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

		/**
		 * Number of pages at the current page size, at least 1.
		 *
		 * @return {number}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		totalPages() {
			return Math.max(
				1,
				Math.ceil(this.sortedProspects.length / this.pageSize),
			)
		},

		/**
		 * The page shown, kept in range when a refresh or an added client
		 * shortens the list.
		 *
		 * @return {number}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		currentPage() {
			return Math.min(this.page, this.totalPages)
		},

		/**
		 * The sorted prospects on the current page. Sorting runs over all of
		 * them first, so a column sort orders the whole list.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		pagedProspects() {
			const start = (this.currentPage - 1) * this.pageSize
			return this.sortedProspects.slice(start, start + this.pageSize)
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
			this.page = 1
		},

		/**
		 * Apply a new page size and go back to the first page.
		 *
		 * @param {number} size - The new page size.
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		onPageSizeChanged(size) {
			this.pageSize = size
			this.page = 1
		},

		/**
		 * The `aria-sort` value for a column header.
		 *
		 * @param {string} key - The column key.
		 * @return {string} `ascending`, `descending` or `none`.
		 * @spec exclude presentational sort-state helper — no business logic
		 */
		ariaSort(key) {
			if (this.sortKey !== key) return 'none'
			return this.sortAsc ? 'ascending' : 'descending'
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
		async addAsClient(prospect) {
			this.addingKvk = prospect.kvkNumber
			try {
				const city = prospect.address?.city ?? ''
				const street = prospect.address?.street ?? ''
				const created = await createWithContact('client', {
					name: prospect.tradeName || t('pipelinq', 'Unknown company'),
					type: 'organization',
					address: [street, city].filter(Boolean).join(', '),
					notes: [
						prospect.kvkNumber ? `KVK: ${prospect.kvkNumber}` : '',
						prospect.sbiDescription || '',
					]
						.filter(Boolean)
						.join(' | '),
				})

				if (created?.id) {
					showSuccess(
						t('pipelinq', 'Added {name} as a client', {
							name: prospect.tradeName,
						}),
					)
					// Drop it from the list: it is a client now, and the
					// discovery query already excludes existing clients by name,
					// so leaving it would offer to add the same company twice.
					this.prospectStore.removeProspect(prospect.kvkNumber)
				} else {
					showError(
						t('pipelinq', 'Could not add this prospect as a client'),
					)
				}
			} catch (error) {
				console.error('Adding a prospect as a client failed', error)
				showError(
					error?.response?.data?.error
						|| t('pipelinq', 'Could not add this prospect as a client'),
				)
			} finally {
				this.addingKvk = null
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
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.prospects-view__table th.sortable button {
	all: unset;
	cursor: pointer;
	user-select: none;
	font-weight: inherit;
}

.prospects-view__table th.sortable button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.prospects-view__score {
	font-weight: bold;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
}

.score--high {
	color: var(--color-text-success);
}

.score--medium {
	color: var(--color-warning-text);
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
