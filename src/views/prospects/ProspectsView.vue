<template>
	<CnIndexPage
		class="prospects-view"
		:title="t('pipelinq', 'Prospects')"
		:showTitle="true"
		:showTitleIcon="false"
		:objects="pagedProspects"
		:columns="columns"
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
		@pageChanged="page = $event">
		<template #below-header>
			<CnDetailCard
				:title="t('pipelinq', 'Ideal customer profile')"
				class="prospects-view__icp">
				<template #header-actions>
					<a :href="profileUrl" class="prospects-view__edit">{{
						t('pipelinq', 'Edit profile')
					}}</a>
				</template>
				<dl v-if="icpFacts.length" class="prospects-view__facts">
					<div v-for="fact in icpFacts" :key="fact.key">
						<dt>{{ fact.label }}</dt>
						<dd>{{ fact.value }}</dd>
					</div>
				</dl>
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
					prospectStore.error
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
import { CnDetailCard, CnIndexPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { createWithContact } from '../../services/contactSyncApi.js'
import { icpFacts } from '../../services/icpFacts.js'
import { useProspectStore } from '../../store/modules/prospect.js'

export default {
	name: 'ProspectsView',
	components: {
		CnDetailCard,
		CnIndexPage,
		NcButton,
		NcEmptyContent,
		Magnify,
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
				{ key: 'address.city', label: t('pipelinq', 'Location') },
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
				{ action: 'refresh-prospects', variant: 'primary' },
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
				? t(
						'pipelinq',
						'Configure your Ideal Customer Profile in admin settings to discover prospects.',
					)
				: this.prospectStore.error
		},

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
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 12px 24px;
	margin: 0;
}

.prospects-view__facts dt {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.prospects-view__facts dd {
	margin: 2px 0 0;
	font-weight: 600;
}

.prospects-view__edit {
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
