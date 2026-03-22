<template>
	<div class="contactmoment-list">
		<div class="contactmoment-list__header">
			<h2>{{ t('pipelinq', 'Contact Moments') }}</h2>
			<div class="contactmoment-list__actions">
				<NcButton type="primary" @click="$router.push({ name: 'ContactmomentNew' })">
					{{ t('pipelinq', 'New contact moment') }}
				</NcButton>
				<NcButton type="secondary" @click="exportCsv">
					{{ t('pipelinq', 'Export CSV') }}
				</NcButton>
			</div>
		</div>

		<div class="contactmoment-list__filters">
			<NcTextField
				:value.sync="searchQuery"
				:label="t('pipelinq', 'Search by subject...')"
				:show-trailing-button="searchQuery !== ''"
				trailing-button-icon="close"
				@trailing-button-click="searchQuery = ''" />
			<div class="filter-row">
				<select v-model="filterChannel" class="filter-select">
					<option value="">{{ t('pipelinq', 'All channels') }}</option>
					<option value="telefoon">{{ t('pipelinq', 'Phone') }}</option>
					<option value="email">{{ t('pipelinq', 'Email') }}</option>
					<option value="balie">{{ t('pipelinq', 'Counter') }}</option>
					<option value="chat">{{ t('pipelinq', 'Chat') }}</option>
					<option value="social">{{ t('pipelinq', 'Social') }}</option>
					<option value="brief">{{ t('pipelinq', 'Letter') }}</option>
				</select>
				<select v-model="filterResult" class="filter-select">
					<option value="">{{ t('pipelinq', 'All results') }}</option>
					<option value="afgehandeld">{{ t('pipelinq', 'Resolved') }}</option>
					<option value="doorverwezen">{{ t('pipelinq', 'Forwarded') }}</option>
					<option value="terugbelverzoek">{{ t('pipelinq', 'Callback') }}</option>
				</select>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="contactmomenten.length === 0" class="contactmoment-list__empty">
			<NcEmptyContent
				:name="t('pipelinq', 'No contact moments')"
				:description="t('pipelinq', 'Register your first contact moment')">
				<template #action>
					<NcButton type="primary" @click="$router.push({ name: 'ContactmomentNew' })">
						{{ t('pipelinq', 'Register contact') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<table v-else class="data-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Date') }}</th>
					<th>{{ t('pipelinq', 'Channel') }}</th>
					<th>{{ t('pipelinq', 'Subject') }}</th>
					<th>{{ t('pipelinq', 'Agent') }}</th>
					<th>{{ t('pipelinq', 'Result') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="cm in contactmomenten"
					:key="cm.id"
					class="clickable-row"
					@click="$router.push({ name: 'ContactmomentDetail', params: { id: cm.id } })">
					<td>{{ formatDate(cm.timestamp) }}</td>
					<td>
						<span class="channel-badge" :class="'channel-badge--' + cm.kanaal">
							{{ cm.kanaal }}
						</span>
					</td>
					<td>{{ cm.onderwerp }}</td>
					<td>{{ cm.agent }}</td>
					<td>{{ cm.resultaat || '-' }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ContactmomentList',
	components: { NcButton, NcLoadingIcon, NcEmptyContent, NcTextField },
	data() {
		return {
			contactmomenten: [],
			loading: false,
			searchQuery: '',
			filterChannel: '',
			filterResult: '',
		}
	},
	mounted() { this.fetchData() },
	methods: {
		async fetchData() {
			this.loading = true
			try { this.contactmomenten = [] } finally { this.loading = false }
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('nl-NL', {
				day: '2-digit', month: '2-digit', year: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},
		exportCsv() {
			// Generate and download CSV
		},
	},
}
</script>

<style scoped>
.contactmoment-list { padding: 20px; max-width: 1100px; margin: 0 auto; }
.contactmoment-list__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.contactmoment-list__actions { display: flex; gap: 8px; }
.contactmoment-list__filters { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.filter-row { display: flex; gap: 8px; }
.filter-select { padding: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-main-background); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.data-table th { font-weight: 600; font-size: 0.85em; color: var(--color-text-lighter); }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--color-background-hover); }
.channel-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; font-weight: 600; text-transform: capitalize; }
.channel-badge--telefoon { background: #bee3f8; color: #2a4365; }
.channel-badge--email { background: #c6f6d5; color: #22543d; }
.channel-badge--balie { background: #fefcbf; color: #744210; }
.channel-badge--chat { background: #e9d8fd; color: #44337a; }
.channel-badge--social { background: #fed7e2; color: #702459; }
.channel-badge--brief { background: #e2e8f0; color: #2d3748; }
.contactmoment-list__empty { padding: 40px 0; }
</style>
