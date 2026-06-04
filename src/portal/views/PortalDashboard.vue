<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="portal-dashboard">
		<h1>{{ t('pipelinq', 'My documents') }}</h1>
		<div class="portal-tabs" role="tablist">
			<button v-for="tab in availableTabs"
				:key="tab.key"
				role="tab"
				:aria-selected="activeTab === tab.key ? 'true' : 'false'"
				:class="{ 'portal-tab-active': activeTab === tab.key }"
				@click="select(tab.key)">
				{{ tab.label }}
			</button>
		</div>

		<p v-if="error" role="alert" class="portal-error">
			{{ error }}
		</p>
		<p v-if="loading">
			{{ t('pipelinq', 'Loading…') }}
		</p>

		<table v-else-if="rows.length" class="portal-table" role="grid">
			<thead>
				<tr>
					<th scope="col">
						{{ t('pipelinq', 'Number') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Date') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Amount') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Status') }}
					</th>
					<th scope="col">
						{{ t('pipelinq', 'Action') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in rows" :key="row.id">
					<td>{{ row.invoiceNumber || row.contractNumber || row.orderNumber }}</td>
					<td>{{ row.date || row.startDate }}</td>
					<td>{{ row.amount || row.value || row.total }}</td>
					<td>{{ row.status }}<span v-if="row.delegatedFrom"> · {{ t('pipelinq', 'shared') }}</span></td>
					<td>
						<button class="portal-button-link"
							:aria-label="downloadLabel(row)"
							@click="download(row)">
							{{ t('pipelinq', 'Download') }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else>
			{{ t('pipelinq', 'Nothing to show yet.') }}
		</p>
	</div>
</template>

<script>
import { portalApi } from '../portalApi.js'

export default {
	name: 'PortalDashboard',
	props: {
		features: { type: Array, default: () => ['invoices', 'contracts', 'orders'] },
	},
	data() {
		return { activeTab: 'invoices', rows: [], loading: false, error: '' }
	},
	computed: {
		availableTabs() {
			const all = [
				{ key: 'invoices', label: t('pipelinq', 'Invoices') },
				{ key: 'contracts', label: t('pipelinq', 'Contracts') },
				{ key: 'orders', label: t('pipelinq', 'Orders') },
			]
			return all.filter((tab) => this.features.includes(tab.key))
		},
	},
	mounted() {
		if (this.availableTabs.length) {
			this.select(this.availableTabs[0].key)
		}
	},
	methods: {
		async select(tab) {
			this.activeTab = tab
			this.loading = true
			this.error = ''
			try {
				const result = await portalApi[tab]()
				this.rows = result.items || []
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not load data.')
				this.rows = []
			} finally {
				this.loading = false
			}
		},
		downloadLabel(row) {
			return t('pipelinq', 'Download {number}', { number: row.invoiceNumber || row.contractNumber || row.orderNumber || '' })
		},
		async download(row) {
			try {
				const objectType = this.activeTab === 'orders' ? 'order' : this.activeTab.replace(/s$/, '')
				const signed = await portalApi.signDocument(row.id, objectType)
				window.open(OC.generateUrl('/apps/pipelinq' + signed.downloadUrl), '_blank')
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not download the document.')
			}
		},
	},
}
</script>
