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
		<ul class="cti-event-log__records" data-testid="cti-event-log-table">
			<li
				v-for="row in events"
				:key="row.id || row.uuid || row.received_at"
				class="cti-event-log__record"
				data-testid="cti-event">
				<dl class="cti-event-log__facts">
					<div>
						<dt>{{ t('pipelinq', 'Received at') }}</dt>
						<dd>{{ row.received_at }}</dd>
					</div>
					<div>
						<dt>{{ t('pipelinq', 'Platform') }}</dt>
						<dd>{{ platformLabel(row.platform) }}</dd>
					</div>
					<div>
						<dt>{{ t('pipelinq', 'Event type') }}</dt>
						<dd>{{ eventTypeLabel(row.event_type) }}</dd>
					</div>
					<div>
						<dt>{{ t('pipelinq', 'Call ID') }}</dt>
						<dd>{{ row.external_call_id }}</dd>
					</div>
					<div>
						<dt>{{ t('pipelinq', 'Signature') }}</dt>
						<dd>{{ row.signature_valid ? '✓' : '✗' }}</dd>
					</div>
					<div
						v-if="row.processing_error"
						class="cti-event-log__fact--wide">
						<dt>{{ t('pipelinq', 'Error') }}</dt>
						<dd>{{ row.processing_error }}</dd>
					</div>
				</dl>
				<div class="cti-event-log__actions">
					<NcButton variant="tertiary" @click="viewPayload(row)">
						{{ t('pipelinq', 'View payload') }}
					</NcButton>
				</div>
			</li>
			<li v-if="!events.length" class="cti-event-log__empty">
				{{ t('pipelinq', 'No webhook events in the selected range.') }}
			</li>
		</ul>
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
import {
	CTI_EVENT_TYPE_LABELS,
	CTI_PLATFORM_LABELS,
	enumLabel,
	enumOptions,
} from '../../utils/enumLabels.js'

const translate = (text) => t('pipelinq', text)

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
			return ['callvoip', 'ringcentral', 'asterisk'].map((value) => ({
				value,
				label: this.platformLabel(value),
			}))
		},

		eventTypeOptions() {
			return enumOptions(CTI_EVENT_TYPE_LABELS, translate)
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

		/**
		 * @param {string} platform A stored platform value.
		 * @return {string} Its label, or the value as stored when unknown.
		 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.6
		 */
		platformLabel(platform) {
			return enumLabel(CTI_PLATFORM_LABELS, platform, translate)
		},

		/**
		 * @param {string} eventType A stored event type.
		 * @return {string} Its label, or the value as stored when unknown.
		 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.6
		 */
		eventTypeLabel(eventType) {
			return enumLabel(CTI_EVENT_TYPE_LABELS, eventType, translate)
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
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 12px;
}

.cti-event-log__records {
	margin: 0;
	padding: 0;
	list-style: none;
}

.cti-event-log__record {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 8px 16px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.cti-event-log__facts {
	display: grid;
	flex: 1 1 320px;
	grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
	gap: 8px 16px;
	margin: 0;
}

.cti-event-log__facts dt,
.cti-event-log__facts dd {
	display: block;
	width: auto;
	margin: 0;
	padding: 0;
	text-align: start;
}

.cti-event-log__facts dt {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.cti-event-log__facts dd {
	overflow-wrap: break-word;
}

.cti-event-log__fact--wide {
	grid-column: 1 / -1;
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
