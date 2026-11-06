<template>
	<CnIndexPage
		class="prospects-view"
		:title="t('pipelinq', 'Prospects')"
		:description="headerLine"
		:showTitle="true"
		:showTitleIcon="false"
		:objects="pagedProspects"
		:columns="columns"
		:activeFilters="activeFilters"
		:filterMenu="true"
		:loading="prospectStore.loading"
		:pagination="pagination"
		:countText="countText"
		:footerNote="
			t(
				'pipelinq',
				'Existing clients are not listed. A company you add becomes an organisation in Relations and leaves this list.',
			)
		"
		:headerButtons="headerButtons"
		:headerActions="headerActions"
		:showAdd="false"
		:showFormDialog="false"
		:showViewAction="false"
		:showEditAction="false"
		:showCopyAction="false"
		:showDeleteAction="false"
		:showMassImport="false"
		:showMassCopy="false"
		:showMassDelete="false"
		:sortKey="sortKey"
		:sortOrder="sortAsc ? 'asc' : 'desc'"
		rowKey="kvkNumber"
		rowTitle="plain"
		:searchPlaceholder="t('pipelinq', 'Search by company, SBI code or place')"
		@sort="onSort"
		@search="onSearch"
		@filterChange="onFilterChange"
		@pageChanged="page = $event">
		<template #below-header>
			<CnDetailCard
				:title="t('pipelinq', 'Ideal customer profile')"
				:collapsible="true"
				chevronStart
				class="prospects-view__icp"
				data-testid="prospects-icp">
				<template #actions>
					<a
						:href="profileUrl"
						class="prospects-view__edit"
						data-testid="prospects-edit-profile"
						@click.stop>
						<Pencil :size="16" />
						{{ t('pipelinq', 'Edit profile') }}
					</a>
				</template>
				<div v-if="icpFacts.length" class="prospects-view__facts">
					<div
						v-for="fact in icpFacts"
						:key="fact.key"
						class="prospects-view__fact">
						<span class="prospects-view__fact-label">{{ fact.label }}</span>
						<span class="prospects-view__fact-value">{{ fact.value }}</span>
					</div>
				</div>
				<p v-else class="prospects-view__unset">
					{{
						t(
							'pipelinq',
							'No profile is set yet. An administrator sets it in the Pipelinq settings.',
						)
					}}
				</p>
			</CnDetailCard>
		</template>
		<template #toolbar-start>
			<CnQuickFilterBar
				inline
				:tabs="lensTabs"
				:counts="lensCounts"
				:activeIndex="lens"
				data-testid="prospects-lenses"
				@update:activeIndex="(value) => selectLens(value)" />
		</template>
		<template #column-fitScore="{ row }">
			<span class="prospects-view__score" :class="scoreClass(row.fitScore)">{{
				row.fitScore
			}}</span>
		</template>
		<template #column-tradeName="{ row }">
			<span :data-testid="`prospect-row-${row.kvkNumber}`">
				<strong>{{ row.tradeName }}</strong>
				<span v-if="row.legalForm" class="prospects-view__sub">{{
					row.legalForm
				}}</span>
			</span>
		</template>
		<template #column-sbiDescription="{ row }">
			{{ row.sbiDescription || '—' }}
			<span v-if="row.sbiCode" class="prospects-view__sub"
				>SBI {{ row.sbiCode }}</span
			>
		</template>
		<template #row-actions="{ row }">
			<NcButton
				variant="secondary"
				:disabled="addingKvk === row.kvkNumber"
				:data-testid="`prospect-add-${row.kvkNumber}`"
				@click="addAsClient(row)">
				{{ t('pipelinq', 'Add as client') }}
			</NcButton>
		</template>
		<template #empty>
			<NcEmptyContent
				:name="
					prospectStore.icp && icpFacts.length === 0
						? t('pipelinq', 'No prospects yet')
						: prospectStore.error
							? t('pipelinq', 'Could not load prospects')
							: t('pipelinq', 'No prospects found')
				"
				:description="emptyDescription">
				<template #icon>
					<Magnify :size="20" />
				</template>
			</NcEmptyContent>
		</template>
	</CnIndexPage>
</template>

<script>
// The prospects page as the PqProspects board draws it: the scored companies
// from the KVK register in the shared index page, with the ideal customer
// profile they are matched on above the list and Add as client per row.
//
// @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
import { CnDetailCard, CnIndexPage, CnQuickFilterBar } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { createWithContact } from '../../services/contactSyncApi.js'
import { icpFacts } from '../../services/icpFacts.js'
import { matchesProspectLens, prospectMatches } from '../../services/prospectLenses.js'
import { useProspectStore } from '../../store/modules/prospect.js'

export default {
	name: 'ProspectsView',
	components: {
		CnDetailCard,
		CnIndexPage,
		CnQuickFilterBar,
		NcButton,
		NcEmptyContent,
		Magnify,
		Pencil,
	},

	// The page config the renderer hands a custom page would otherwise fall
	// through onto CnIndexPage.
	inheritAttrs: false,

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
			/** The lens above the list: 0 every company, 1 good fit, 2 new this week. */
			lens: 0,
			search: '',
			/** The Filter menu's choices: { field: [values] }. */
			activeFilters: {},
		}
	},

	computed: {
		/**
		 * The list's columns, in the board's order.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		columns() {
			return [
				{ key: 'fitScore', label: t('pipelinq', 'Score'), sortable: true },
				{
					key: 'tradeName',
					label: t('pipelinq', 'Company'),
					sortable: true,
				},
				{ key: 'sbiDescription', label: t('pipelinq', 'Industry (SBI)') },
				{
					key: 'employeeCount',
					label: t('pipelinq', 'Employees'),
					sortable: true,
				},
				{
					key: 'address.city',
					label: t('pipelinq', 'Location'),
					// The places in the list, for the Filter menu.
					enum: [...new Set((this.prospectStore.prospects || []).map((p) => p.address?.city).filter(Boolean))].sort(),
				},
				{ key: 'kvkNumber', label: t('pipelinq', 'KVK') },
			]
		},

		/**
		 * Download and Refresh prospects, as the board draws them.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		headerButtons() {
			return [
				{ action: 'export', label: t('pipelinq', 'Download') },
				{
					action: 'refresh-prospects',
					variant: 'primary',
					icon: 'Refresh',
					label: t('pipelinq', 'Refresh prospects'),
				},
			]
		},

		/**
		 * Refresh prospects bypasses the cached list.
		 *
		 * @return {Array<object>}
		 * @spec exclude trivial store passthrough — no business logic
		 */
		headerActions() {
			return [
				{
					id: 'refresh-prospects',
					label: t('pipelinq', 'Refresh prospects'),
					icon: 'Refresh',
					handler: () => this.refresh(),
				},
			]
		},

		/**
		 * The lenses above the list, as the board draws them.
		 *
		 * @return {Array<{label: string}>}
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		lensTabs() {
			return [
				{ label: t('pipelinq', 'All') },
				{ label: t('pipelinq', 'Good fit') },
				{ label: t('pipelinq', 'New this week') },
			]
		},

		/**
		 * How many companies each lens holds, within the search and the Filter menu.
		 *
		 * @return {number[]}
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		lensCounts() {
			return this.lensTabs.map((_, index) => this.narrowedProspects.filter((p) => matchesProspectLens(p, index, new Date())).length)
		},

		/**
		 * The companies the search and the Filter menu leave.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		narrowedProspects() {
			return (this.prospectStore.prospects || []).filter((p) => prospectMatches(p, this.search, this.activeFilters))
		},

		/**
		 * The line under the title: how many companies, best first, and when
		 * the list was refreshed.
		 *
		 * @return {string}
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		headerLine() {
			const parts = [
				t('pipelinq', '{shown} of {total} companies from the KVK register that fit your profile', {
					shown: this.sortedProspects.length,
					total: (this.prospectStore.prospects || []).length,
				}),
				t('pipelinq', 'best first'),
			]
			const at = this.prospectStore.cachedAt ? new Date(this.prospectStore.cachedAt) : null
			if (at && !Number.isNaN(at.getTime())) {
				const time = at.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
				parts.push(at.toDateString() === new Date().toDateString()
					? t('pipelinq', 'refreshed today at {time}', { time })
					: t('pipelinq', 'refreshed on {date}', { date: at.toLocaleDateString() }))
			}
			return parts.join(' · ')
		},

		/**
		 * The count line: how many companies match, best first.
		 *
		 * @return {string}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		countText() {
			return t(
				'pipelinq',
				'{shown} of {total} companies from the KVK register that fit your profile · best first',
			)
		},

		/**
		 * The page state the index page draws its footer from.
		 *
		 * @return {object}
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		pagination() {
			return {
				currentPage: this.currentPage,
				totalPages: this.totalPages,
				totalItems: this.sortedProspects.length,
				pageSize: this.pageSize,
			}
		},

		/**
		 * The profile's criteria as label and value, empty ones left out.
		 *
		 * @return {Array<{key: string, label: string, value: string}>}
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		icpFacts() {
			return icpFacts(this.prospectStore.icp, (text) => t('pipelinq', text))
		},

		/**
		 * Where an administrator edits the profile.
		 *
		 * @return {string}
		 * @spec exclude a fixed settings link — no business logic
		 */
		profileUrl() {
			return generateUrl('/settings/admin/pipelinq')
		},

		/**
		 * The line under the empty state: why there is nothing to show.
		 *
		 * @return {string}
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		emptyDescription() {
			if (!this.prospectStore.error) {
				return t(
					'pipelinq',
					'No companies currently match your Ideal Customer Profile.',
				)
			}
			return this.prospectStore.error.includes('Ideal Customer Profile')
				? t('pipelinq', 'Set an ideal customer profile first.')
				: this.prospectStore.error
		},

		/**
		 * Prospects sorted by the active sort column/direction.
		 *
		 * @return {Array<object>} The sorted prospect list.
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		sortedProspects() {
			const list = this.narrowedProspects.filter((p) => matchesProspectLens(p, this.lens, new Date()))
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
		 * A lens chip was chosen; choosing the active one again goes back to All.
		 *
		 * @param {number|null} value The lens index.
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		selectLens(value) {
			this.lens = value === null ? 0 : value
			this.page = 1
		},

		/**
		 * The search field: company, SBI code or place.
		 *
		 * @param {string} value The search text.
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		onSearch(value) {
			this.search = typeof value === 'string' ? value : ''
			this.page = 1
		},

		/**
		 * A choice in the Filter menu.
		 *
		 * @param {{key: string, values: string[]}} payload The field and its chosen values.
		 * @spec openspec/specs/prospect-discovery/spec.md
		 */
		onFilterChange(payload) {
			if (!payload || !payload.key) return
			const next = { ...this.activeFilters }
			if (Array.isArray(payload.values) && payload.values.length > 0) {
				next[payload.key] = payload.values
			} else {
				delete next[payload.key]
			}
			this.activeFilters = next
			this.page = 1
		},

		/**
		 * Sort the whole list by the column the index page reports.
		 *
		 * @param {{key?: string, order?: string, keys?: Array<{key: string, order: string}>}} event The sort event.
		 * @spec openspec/changes/refactor-pipelinq-ia-alignment/tasks.md#task-20
		 */
		onSort(event) {
			const first = event?.keys?.[0] || event || {}
			if (first.key) {
				this.sortKey = first.key
				this.sortAsc = first.order === 'asc'
				this.page = 1
			}
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
.prospects-view__icp {
	margin-bottom: 16px;
}

.prospects-view__facts {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 28px;
}

.prospects-view__fact {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.prospects-view__fact-label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.prospects-view__fact-value {
	font-size: 14px;
	font-weight: 600;
}

.prospects-view__edit {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-primary-element);
	font-size: 14px;
	font-weight: 600;
	text-decoration: underline;
}

.prospects-view__unset {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.prospects-view__sub {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
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
</style>
