<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufEndpoints is the admin page used to configure StUF-ZKN/BG endpoints
  - per gemeente (zaaksysteem connection profile).
  -
  - @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-011
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'StUF endpoints')"
		:description="t('pipelinq', 'Configure StUF-ZKN/BG zaaksysteem endpoints per gemeente (one row per receiving system).')">
		<table class="stuf-endpoints__table" data-testid="stuf-endpoints-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Name') }}</th>
					<th>{{ t('pipelinq', 'Gemeente code') }}</th>
					<th>{{ t('pipelinq', 'Application') }}</th>
					<th>{{ t('pipelinq', 'SOAP version') }}</th>
					<th>{{ t('pipelinq', 'Strategy') }}</th>
					<th>{{ t('pipelinq', 'Health') }}</th>
					<th>{{ t('pipelinq', 'Active') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in endpoints" :key="row.id">
					<td>{{ row.naam }}</td>
					<td>{{ row.gemeenteCode }}</td>
					<td>{{ row.ontvangerApplicatie }}</td>
					<td>{{ row.soapVersion }}</td>
					<td>{{ row.zaakIdentificatieStrategie || '—' }}</td>
					<td>
						<span class="stuf-endpoints__health" :class="healthClass(row)">
							{{ healthLabel(row) }}
						</span>
					</td>
					<td>{{ row.actief ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive') }}</td>
				</tr>
				<tr v-if="!endpoints.length">
					<td colspan="7" class="stuf-endpoints__empty">
						{{ t('pipelinq', 'No StUF endpoints configured yet.') }}
					</td>
				</tr>
			</tbody>
		</table>
		<p class="stuf-endpoints__note">
			{{ t('pipelinq', 'Endpoints, credentials (WSSE), and mTLS certificates are managed by the platform operator. Reach out to your administrator to add or rotate them.') }}
		</p>
		<p v-if="loadError" class="stuf-endpoints__error">
			{{ loadError }}
		</p>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { listEndpoints } from '../../services/stufApi.js'

export default {
	name: 'StufEndpoints',
	components: { NcSettingsSection },
	data() {
		return {
			endpoints: [],
			loadError: '',
		}
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			try {
				const data = await listEndpoints()
				this.endpoints = Array.isArray(data.items) ? data.items : []
				this.loadError = ''
			} catch (e) {
				this.loadError = t('pipelinq', 'Failed to load StUF endpoints')
				showError(this.loadError)
			}
		},
		healthClass(row) {
			const state = row && row.health && row.health.state ? row.health.state : 'ok'
			return 'stuf-endpoints__health--' + state
		},
		healthLabel(row) {
			const state = row && row.health && row.health.state ? row.health.state : 'ok'
			if (state === 'circuit_open') {
				return t('pipelinq', 'Circuit open')
			}
			if (state === 'degraded') {
				return t('pipelinq', 'Degraded')
			}
			return t('pipelinq', 'OK')
		},
	},
}
</script>

<style scoped>
.stuf-endpoints__table {
	width: 100%;
	border-collapse: collapse;
}
.stuf-endpoints__table th,
.stuf-endpoints__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
.stuf-endpoints__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
.stuf-endpoints__health {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 11px;
	font-weight: bold;
}
.stuf-endpoints__health--ok {
	background: var(--color-success);
	color: white;
}
.stuf-endpoints__health--degraded {
	background: var(--color-warning);
	color: white;
}
.stuf-endpoints__health--circuit_open {
	background: var(--color-error);
	color: white;
}
.stuf-endpoints__note {
	color: var(--color-text-maxcontrast);
	margin-top: 12px;
	font-size: 13px;
}
.stuf-endpoints__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>
