<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Dashboard donut widget — hours per billing category. REQ-BCT-004.

  Loads:
   - all billing categories (for color + name lookup) via the billingCategory
     Pinia store
   - all approved time entries via the shared object store and groups
     `hours` per `billingCategory` slug/uuid client-side

  Renders a CnChartWidget donut. Clicking a segment navigates to the
  time-entry list filtered by the chosen category.

  The "Hours by billing category" title and "Uncategorized" bucket
  use t() — never hardcoded.
-->
<template>
	<NcEmptyContent
		v-if="!loading && !hasData"
		:name="t('pipelinq', 'No hours recorded')"
		:description="
			t(
				'pipelinq',
				'When approved time entries are present, hours will be shown per billing category here.',
			)
		" />

	<CnChartWidget
		v-else
		type="donut"
		:title="t('pipelinq', 'Hours by billing category')"
		:labels="labels"
		:series="series"
		:colors="colors"
		@segment-click="onSegmentClick" />
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { NcEmptyContent } from '@nextcloud/vue'
import { useBillingCategoryStore } from '../../store/modules/billingCategory.js'
import { initializeStores } from '../../store/store.js'
import { generateUrl } from '@nextcloud/router'

const UNCATEGORIZED_KEY = '__uncategorized__'

export default {
	name: 'BillingCategoryWidget',
	components: { CnChartWidget, NcEmptyContent },
	setup() {
		const billingCategoryStore = useBillingCategoryStore()
		return { billingCategoryStore }
	},
	data() {
		return {
			loading: true,
			timeEntries: [],
		}
	},
	computed: {
		/**
		 * Map slug/uuid -> category record for fast lookup.
		 *
		 * @return {object} Category index.
		 */
		categoryIndex() {
			const idx = {}
			for (const c of this.billingCategoryStore.categories || []) {
				if (c.id) idx[c.id] = c
				if (c['@self']?.uuid) idx[c['@self'].uuid] = c
				if (c['@self']?.slug) idx[c['@self'].slug] = c
			}
			return idx
		},
		/**
		 * Total hours per category key (slug/uuid). Unresolved keys roll
		 * into the "Uncategorized" bucket.
		 *
		 * @return {Array<object>} [{key, label, hours, color}, …]
		 */
		buckets() {
			const totals = new Map()
			for (const te of this.timeEntries) {
				const key = te.billingCategory || UNCATEGORIZED_KEY
				const hours = Number(te.hours) || 0
				totals.set(key, (totals.get(key) || 0) + hours)
			}
			const result = []
			for (const [key, hours] of totals.entries()) {
				const cat =
					key !== UNCATEGORIZED_KEY ? this.categoryIndex[key] : null
				result.push({
					key,
					label: cat ? cat.name : t('pipelinq', 'Uncategorized'),
					hours: Math.round(hours * 100) / 100,
					color: cat?.color || '#6c757d',
				})
			}
			return result
		},
		labels() {
			return this.buckets.map((b) => b.label)
		},
		series() {
			return this.buckets.map((b) => b.hours)
		},
		colors() {
			return this.buckets.map((b) => b.color)
		},
		hasData() {
			return this.buckets.some((b) => b.hours > 0)
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Load categories + time entries. REQ-BCT-004.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				await this.billingCategoryStore.fetchCategories()
				const { objectStore } = await initializeStores()
				const config = objectStore.objectTypeRegistry
				if (config.timeEntry) {
					const url = generateUrl(
						'/apps/openregister/api/objects/'
							+ config.timeEntry.register
							+ '/'
							+ config.timeEntry.schema
							+ '?_limit=500',
					)
					const response = await fetch(url, {
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					})
					if (response.ok) {
						const data = await response.json()
						this.timeEntries = data.results || data || []
					}
				}
			} catch (err) {
				console.error('BillingCategoryWidget load error:', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate to the time entry list filtered by the clicked segment.
		 *
		 * REQ-BCT-004 scenario: "Widget segment click filters time entry list".
		 *
		 * @param {number} index The segment index.
		 */
		onSegmentClick(index) {
			const bucket = this.buckets[index]
			if (!bucket) return
			if (bucket.key === UNCATEGORIZED_KEY) {
				this.$router
					.push({
						path: '/time-entries',
						query: { billingCategory: 'null' },
					})
					.catch(() => {})
				return
			}
			this.$router
				.push({
					path: '/time-entries',
					query: { billingCategory: bucket.key },
				})
				.catch(() => {})
		},
	},
}
</script>
