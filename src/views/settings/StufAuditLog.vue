<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - StufAuditLog is the admin audit-log inspector for the StUF-ZKN/BG adapter.
  - Reads /api/stuf/messages and surfaces the per-call envelope log
  - (REQ-STUF-008). Click a row to inspect the full envelope XML; the
  - retries[] history and fout payload appear in the row when present.
  -
  - @spec openspec/changes/stuf-zkn-bg-adapter/specs/stuf-zkn-bg-adapter/spec.md#REQ-STUF-008
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'StUF audit log')"
		:description="t('pipelinq', 'Per-call audit log for outbound and inbound StUF SOAP envelopes (full XML, HTTP status, duration, retries).')">
		<div class="stuf-audit-log__filters">
			<NcTextField
				v-model="filters.endpointId"
				:label="t('pipelinq', 'Endpoint ID')"
				:placeholder="t('pipelinq', 'e.g. stuf-ep-amersfoort-key2zaken')" />
			<NcSelect
				v-model="filters.berichtSoort"
				:options="berichtSoortOptions"
				:input-label="t('pipelinq', 'Bericht soort')"
				clearable />
			<NcSelect
				v-model="filters.status"
				:options="statusOptions"
				:input-label="t('pipelinq', 'Status')"
				clearable />
			<NcButton type="primary" @click="reload">
				{{ t('pipelinq', 'Reload') }}
			</NcButton>
			<NcButton type="tertiary" @click="exportCsv">
				{{ t('pipelinq', 'Export CSV') }}
			</NcButton>
		</div>
		<table class="stuf-audit-log__table" data-testid="stuf-audit-log-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Sent at') }}</th>
					<th>{{ t('pipelinq', 'Direction') }}</th>
					<th>{{ t('pipelinq', 'Bericht') }}</th>
					<th>{{ t('pipelinq', 'Functie') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'HTTP') }}</th>
					<th>{{ t('pipelinq', 'Duration (ms)') }}</th>
					<th class="stuf-audit-log__actions" />
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in messages" :key="row.id || row.referentienummer">
					<td>{{ row.verzondenOp }}</td>
					<td>{{ row.richting }}</td>
					<td>{{ row.berichtSoort }}</td>
					<td>{{ row.functie }}</td>
					<td>
						<span class="stuf-audit-log__status" :class="statusClass(row.status)">{{ row.status }}</span>
					</td>
					<td>{{ row.httpStatus || '—' }}</td>
					<td>{{ row.duurMs || '—' }}</td>
					<td class="stuf-audit-log__actions">
						<NcButton type="tertiary" @click="inspect(row)">
							{{ t('pipelinq', 'Inspect') }}
						</NcButton>
					</td>
				</tr>
				<tr v-if="!messages.length">
					<td colspan="8" class="stuf-audit-log__empty">
						{{ t('pipelinq', 'No StUF messages match the filters.') }}
					</td>
				</tr>
			</tbody>
		</table>
		<NcDialog
			v-if="inspectRow"
			:name="t('pipelinq', 'StUF envelope')"
			:open="!!inspectRow"
			size="large"
			@closing="inspectRow = null">
			<div class="stuf-audit-log__details">
				<h4>{{ t('pipelinq', 'Request envelope') }}</h4>
				<pre class="stuf-audit-log__pre">{{ inspectRow.envelopeXml || t('pipelinq', '(no envelope)') }}</pre>
				<h4 v-if="inspectRow.responseEnvelopeXml">
					{{ t('pipelinq', 'Response envelope') }}
				</h4>
				<pre v-if="inspectRow.responseEnvelopeXml" class="stuf-audit-log__pre">{{ inspectRow.responseEnvelopeXml }}</pre>
				<h4 v-if="hasRetries(inspectRow)">
					{{ t('pipelinq', 'Retries') }}
				</h4>
				<table v-if="hasRetries(inspectRow)" class="stuf-audit-log__retries">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Attempt') }}</th>
							<th>{{ t('pipelinq', 'Timestamp') }}</th>
							<th>{{ t('pipelinq', 'HTTP') }}</th>
							<th>{{ t('pipelinq', 'Duration (ms)') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(retry, index) in inspectRow.retries" :key="index">
							<td>{{ retry.poging }}</td>
							<td>{{ retry.timestamp }}</td>
							<td>{{ retry.httpStatus || '—' }}</td>
							<td>{{ retry.duurMs || '—' }}</td>
						</tr>
					</tbody>
				</table>
				<h4 v-if="inspectRow.fout">
					{{ t('pipelinq', 'Error') }}
				</h4>
				<pre v-if="inspectRow.fout" class="stuf-audit-log__pre">{{ pretty(inspectRow.fout) }}</pre>
			</div>
		</NcDialog>
		<p v-if="loadError" class="stuf-audit-log__error">
			{{ loadError }}
		</p>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcDialog, NcSelect, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { listMessages } from '../../services/stufApi.js'

export default {
	name: 'StufAuditLog',
	components: { NcButton, NcDialog, NcSelect, NcSettingsSection, NcTextField },
	data() {
		return {
			messages: [],
			loadError: '',
			inspectRow: null,
			filters: {
				endpointId: '',
				berichtSoort: '',
				status: '',
			},
		}
	},
	computed: {
		berichtSoortOptions() {
			return ['Lk01', 'Lk02', 'Lk03', 'Bv01', 'Lv01', 'La01', 'Du01', 'Fo02']
		},
		statusOptions() {
			return ['verzonden', 'bevestigd', 'fout', 'wacht_op_retry']
		},
	},
	mounted() {
		this.reload()
	},
	methods: {
		async reload() {
			try {
				const data = await listMessages({
					endpointId: this.filters.endpointId,
					berichtSoort: this.filters.berichtSoort,
					status: this.filters.status,
					limit: 100,
				})
				this.messages = Array.isArray(data.items) ? data.items : []
				this.loadError = ''
			} catch (e) {
				this.loadError = t('pipelinq', 'Failed to load StUF audit log')
				showError(this.loadError)
			}
		},
		inspect(row) {
			this.inspectRow = row
		},
		hasRetries(row) {
			return Array.isArray(row.retries) && row.retries.length > 0
		},
		statusClass(status) {
			return 'stuf-audit-log__status--' + (status || 'unknown')
		},
		pretty(value) {
			try {
				return JSON.stringify(value, null, 2)
			} catch (e) {
				return String(value)
			}
		},
		exportCsv() {
			const headers = ['verzondenOp', 'richting', 'berichtSoort', 'functie', 'status', 'httpStatus', 'duurMs', 'referentienummer', 'zaakIdentificatie']
			const lines = [headers.join(',')]
			for (const row of this.messages) {
				lines.push(headers.map((h) => JSON.stringify(row[h] == null ? '' : row[h])).join(','))
			}
			const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = 'stuf-audit-log.csv'
			a.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.stuf-audit-log__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 12px;
	align-items: end;
	flex-wrap: wrap;
}
.stuf-audit-log__table {
	width: 100%;
	border-collapse: collapse;
}
.stuf-audit-log__table th,
.stuf-audit-log__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
.stuf-audit-log__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
.stuf-audit-log__actions {
	text-align: right;
}
.stuf-audit-log__status {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 11px;
	font-weight: bold;
}
.stuf-audit-log__status--verzonden {
	background: var(--color-primary);
	color: white;
}
.stuf-audit-log__status--bevestigd {
	background: var(--color-success);
	color: white;
}
.stuf-audit-log__status--fout {
	background: var(--color-error);
	color: white;
}
.stuf-audit-log__status--wacht_op_retry {
	background: var(--color-warning);
	color: white;
}
.stuf-audit-log__details h4 {
	margin: 16px 0 4px;
}
.stuf-audit-log__pre {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: var(--border-radius);
	overflow: auto;
	font-size: 11px;
	max-height: 320px;
	white-space: pre-wrap;
	word-break: break-all;
}
.stuf-audit-log__retries {
	width: 100%;
	border-collapse: collapse;
}
.stuf-audit-log__retries th,
.stuf-audit-log__retries td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
.stuf-audit-log__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>
