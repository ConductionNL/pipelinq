<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'CTI webhook event log')"
		:description="t('pipelinq', 'Inbound telephony webhook events from the last 30 days, for debugging.')">
		<div class="cti-eventlog__filters">
			<NcSelect v-model="platformFilter"
				class="cti-eventlog__filter"
				:input-label="t('pipelinq', 'Platform')"
				:options="platformOptions"
				label="label"
				@input="load" />
			<NcSelect v-model="eventTypeFilter"
				class="cti-eventlog__filter"
				:input-label="t('pipelinq', 'Event type')"
				:options="eventTypeOptions"
				label="label"
				@input="load" />
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<table v-else class="cti-eventlog__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Received at') }}</th>
					<th>{{ t('pipelinq', 'Platform') }}</th>
					<th>{{ t('pipelinq', 'Event type') }}</th>
					<th>{{ t('pipelinq', 'Call ID') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(event, index) in events" :key="index">
					<td>{{ event.receivedAt }}</td>
					<td>{{ event.platform }}</td>
					<td>{{ event.eventType }}</td>
					<td>{{ event.externalCallId || '—' }}</td>
					<td :class="event.processingError ? 'cti-eventlog__error' : 'cti-eventlog__ok'">
						{{ event.processingError || t('pipelinq', 'Processed') }}
					</td>
				</tr>
				<tr v-if="events.length === 0">
					<td colspan="5" class="cti-eventlog__empty">
						{{ t('pipelinq', 'No webhook events in the last 30 days.') }}
					</td>
				</tr>
			</tbody>
		</table>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CtiEventLog',
	components: {
		NcSettingsSection,
		NcSelect,
		NcLoadingIcon,
	},
	data() {
		return {
			events: [],
			loading: true,
			platformFilter: null,
			eventTypeFilter: null,
		}
	},
	computed: {
		platformOptions() {
			return [
				{ id: '', label: t('pipelinq', 'All platforms') },
				{ id: 'callvoip', label: 'CallVoip' },
				{ id: 'ringcentral', label: 'RingCentral' },
				{ id: 'asterisk', label: 'Asterisk' },
			]
		},
		eventTypeOptions() {
			return [
				{ id: '', label: t('pipelinq', 'All event types') },
				{ id: 'ringing', label: t('pipelinq', 'Ringing') },
				{ id: 'answered', label: t('pipelinq', 'Answered') },
				{ id: 'ended', label: t('pipelinq', 'Ended') },
				{ id: 'abandoned', label: t('pipelinq', 'Abandoned') },
				{ id: 'presence_changed', label: t('pipelinq', 'Presence changed') },
			]
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t,
		async load() {
			this.loading = true
			try {
				const params = {}
				if (this.platformFilter && this.platformFilter.id) {
					params.platform = this.platformFilter.id
				}
				if (this.eventTypeFilter && this.eventTypeFilter.id) {
					params.eventType = this.eventTypeFilter.id
				}
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/cti/event-log'), { params })
				this.events = data.events || []
			} catch (e) {
				showError(t('pipelinq', 'Event log could not be loaded'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.cti-eventlog__filters {
	display: flex;
	gap: 16px;
	margin-bottom: 12px;
}

.cti-eventlog__table {
	width: 100%;
	border-collapse: collapse;
}

.cti-eventlog__table th,
.cti-eventlog__table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.cti-eventlog__error {
	color: var(--color-error);
}

.cti-eventlog__empty {
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
