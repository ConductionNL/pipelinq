<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiEventLog is the admin event-log inspector for the CTI adapter.
  - Reads /api/cti/event-log (30 days of webhook receipts).
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.6
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'CTI webhook event log')"
		:description="
			t(
				'pipelinq',
				'Last 30 days of inbound webhook events grouped by platform.',
			)
		">
		<div class="cti-event-log__filters">
			<NcSelect
				v-model="filters.platform"
				:options="platformOptions"
				:inputLabel="t('pipelinq', 'Platform')"
				label="label"
				:reduce="(o) => o.value"
				clearable />
			<NcSelect
				v-model="filters.event_type"
				:options="eventTypeOptions"
				:inputLabel="t('pipelinq', 'Event type')"
				label="label"
				:reduce="(o) => o.value"
				clearable />
			<NcButton variant="primary" :disabled="loading" @click="reload">
				<template v-if="loading" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ loading ? t('pipelinq', 'Reloading…') : t('pipelinq', 'Reload') }}
			</NcButton>
		</div>
		<table class="cti-event-log__table" data-testid="cti-event-log-table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Received at') }}</th>
					<th scope="col">{{ t('pipelinq', 'Platform') }}</th>
					<th scope="col">{{ t('pipelinq', 'Event type') }}</th>
					<th scope="col">{{ t('pipelinq', 'Call ID') }}</th>
					<th scope="col">{{ t('pipelinq', 'Signature') }}</th>
					<th scope="col">{{ t('pipelinq', 'Error') }}</th>
					<th scope="col" class="cti-event-log__actions" />
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="row in events"
					:key="row.id || row.uuid || row.received_at">
					<td>{{ row.received_at }}</td>
					<td>{{ row.platform }}</td>
					<td>{{ row.event_type }}</td>
					<td>{{ row.external_call_id }}</td>
					<td>{{ row.signature_valid ? '✓' : '✗' }}</td>
					<td>{{ row.processing_error || '' }}</td>
					<td class="cti-event-log__actions">
						<NcButton variant="tertiary" @click="viewPayload(row)">
							{{ t('pipelinq', 'View payload') }}
						</NcButton>
					</td>
				</tr>
				<tr v-if="!events.length">
					<td colspan="7" class="cti-event-log__empty">
						{{
							t('pipelinq', 'No webhook events in the selected range.')
						}}
					</td>
				</tr>
			</tbody>
		</table>
		<CtiPayloadDialog
			v-if="payloadRow"
			:payload="payloadRow.payload_json"
			@close="payloadRow = null" />
		<p class="cti-event-log__note">
			{{ t('pipelinq', 'Showing events from the last 30 days.') }}
		</p>
	</NcSettingsSection>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcSelect, NcSettingsSection } from '@nextcloud/vue'
import CtiPayloadDialog from '../../dialogs/CtiPayloadDialog.vue'
import { getEventLog } from '../../services/ctiApi.js'

export default {
	name: 'CtiEventLog',
	components: {
		CtiPayloadDialog,
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
	},

	data() {
		return {
			events: [],
			payloadRow: null,
			loading: false,
			filters: {
				platform: '',
				event_type: '',
			},
		}
	},

	computed: {
		platformOptions() {
			return [
				{ value: 'callvoip', label: 'CallVoip' },
				{ value: 'ringcentral', label: 'RingCentral' },
				{ value: 'asterisk', label: 'Asterisk' },
			]
		},

		eventTypeOptions() {
			return [
				'ringing',
				'answered',
				'ended',
				'abandoned',
				'transferred',
				'presence',
				'recording',
			].map((v) => ({ value: v, label: v }))
		},
	},

	watch: {
		'filters.platform': 'reload',
		'filters.event_type': 'reload',
	},

	mounted() {
		this.reload()
	},

	methods: {
		/**
		 * Reload the CTI event log from the backing store.
		 *
		 * @spec exclude presentational reload helper — no business logic
		 */
		async reload() {
			this.loading = true
			try {
				const data = await getEventLog(this.filters)
				this.events = (data && data.events) || []
			} catch (e) {
				showError(
					t('pipelinq', 'Failed to load event log: {error}', {
						error: e.message || 'network error',
					}),
				)
			} finally {
				this.loading = false
			}
		},

		viewPayload(row) {
			this.payloadRow = row
		},
	},
}
</script>

<style scoped>
.cti-event-log__filters {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 12px;
}

.cti-event-log__table {
	width: 100%;
	border-collapse: collapse;
}

.cti-event-log__table th,
.cti-event-log__table td {
	padding: 6px 12px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.cti-event-log__actions {
	text-align: end;
}

.cti-event-log__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 12px;
}

.cti-event-log__note {
	margin-top: 8px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}
</style>
