<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-dashboard">
		<div class="avg-dashboard__header">
			<h2>{{ t('pipelinq', 'AVG requests') }}</h2>
			<NcButton type="primary" @click="openIntake">
				{{ t('pipelinq', 'New AVG request') }}
			</NcButton>
		</div>

		<div class="avg-dashboard__filters">
			<NcSelect v-model="statusFilter"
				:input-label="t('pipelinq', 'Status')"
				:options="statusOptions"
				label="label"
				@update:model-value="load" />
			<NcSelect v-model="articleFilter"
				:input-label="t('pipelinq', 'Article')"
				:options="articleOptions"
				label="label"
				@update:model-value="load" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="requests.length === 0"
			:name="t('pipelinq', 'No AVG requests found')" />

		<table v-else class="avg-dashboard__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Reference') }}</th>
					<th>{{ t('pipelinq', 'Article') }}</th>
					<th>{{ t('pipelinq', 'Data subject') }}</th>
					<th>{{ t('pipelinq', 'Deadline') }}</th>
					<th>{{ t('pipelinq', 'Handler') }}</th>
					<th>{{ t('pipelinq', 'Flags') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="req in requests" :key="rowKey(req)" class="avg-dashboard__row" @click="open(req)">
					<td>{{ req.kenmerk }}</td>
					<td>{{ articleLabel(req.artikel) }}</td>
					<td>{{ maskedName(req.verzoekerNaam) }}</td>
					<td>
						<DeadlineCounter :deadline="req.wettelijkeTermijnVerloopt" :extended-days="req.verlengdMet || 0" />
					</td>
					<td>{{ req.behandelaar }}</td>
					<td><DpiaFlagBadge :flagged="!!req.dpiaFlag" /></td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import DeadlineCounter from '../../components/avg/DeadlineCounter.vue'
import DpiaFlagBadge from '../../components/avg/DpiaFlagBadge.vue'
import { ARTICLES, articleLabel } from '../../utils/avg/avgLabels.js'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'AvgDashboard',
	components: {
		DeadlineCounter,
		DpiaFlagBadge,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},
	data() {
		return {
			requests: [],
			loading: true,
			statusFilter: { value: '', label: this.t('pipelinq', 'All statuses') },
			articleFilter: { value: '', label: this.t('pipelinq', 'All articles') },
		}
	},
	computed: {
		/**
		 * The status filter options.
		 *
		 * @return {Array<object>} The options.
		 */
		statusOptions() {
			return [
				{ value: '', label: this.t('pipelinq', 'All statuses') },
				{ value: 'in-behandeling', label: this.t('pipelinq', 'In treatment') },
				{ value: 'afgerond', label: this.t('pipelinq', 'Resolved') },
				{ value: 'gearchiveerd', label: this.t('pipelinq', 'Archived') },
			]
		},
		/**
		 * The article filter options.
		 *
		 * @return {Array<object>} The options.
		 */
		articleOptions() {
			return [{ value: '', label: this.t('pipelinq', 'All articles') }]
				.concat(ARTICLES.map((a) => ({ value: a, label: articleLabel(a) })))
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		articleLabel,
		/**
		 * Stable row key.
		 *
		 * @param {object} req The request.
		 * @return {string} The key.
		 */
		rowKey(req) {
			return req.id || req['@self']?.id || req.kenmerk
		},
		/**
		 * Mask a name for the list view (first letter + surname initial).
		 *
		 * @param {string} name The full name.
		 * @return {string} The masked name.
		 */
		maskedName(name) {
			if (!name) {
				return '—'
			}
			return name.replace(/\B\w/g, '·')
		},
		/**
		 * Load the requests with the active filters.
		 */
		async load() {
			this.loading = true
			try {
				const { verzoeken } = await avgApi.list({
					status: this.statusFilter.value,
					artikel: this.articleFilter.value,
				})
				this.requests = verzoeken || []
			} catch (e) {
				this.requests = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Open a request detail.
		 *
		 * @param {object} req The request.
		 */
		open(req) {
			this.$router.push({ name: 'AvgRequestDetail', params: { id: this.rowKey(req) } })
		},
		/**
		 * Open the intake page.
		 */
		openIntake() {
			this.$router.push({ name: 'AvgIntake' })
		},
	},
}
</script>

<style scoped>
.avg-dashboard { padding: 16px; display: flex; flex-direction: column; gap: 16px; }
.avg-dashboard__header { display: flex; justify-content: space-between; align-items: center; }
.avg-dashboard__filters { display: flex; gap: 12px; }
.avg-dashboard__table { width: 100%; border-collapse: collapse; }
.avg-dashboard__table th, .avg-dashboard__table td {
	text-align: left; padding: 8px; border-bottom: 1px solid var(--color-border);
}
.avg-dashboard__row { cursor: pointer; }
.avg-dashboard__row:hover { background: var(--color-background-hover); }
</style>
