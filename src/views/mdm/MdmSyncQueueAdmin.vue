<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="mdm-sync">
		<div class="mdm-sync__header">
			<h2>{{ t('pipelinq', 'Sync queue') }}</h2>
			<NcSelect v-model="status"
				:options="statusOptions"
				:input-label="t('pipelinq', 'Status')"
				:placeholder="t('pipelinq', 'All statuses')"
				@update:model-value="fetchData" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="!items.length"
			:name="t('pipelinq', 'No sync queue items')" />

		<table v-else class="mdm-sync__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Target system') }}</th>
					<th>{{ t('pipelinq', 'Change') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Attempts') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="item in items" :key="item.id">
					<td>{{ item.targetSystem }}</td>
					<td>{{ item.changeType }}</td>
					<td>{{ item.status }}</td>
					<td>{{ item.attemptCount || 0 }}</td>
					<td>
						<NcButton v-if="canRetry(item)"
							type="tertiary"
							@click="retry(item)">
							{{ t('pipelinq', 'Retry') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

export default {
	name: 'MdmSyncQueueAdmin',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect },
	data() {
		return {
			loading: true,
			items: [],
			status: null,
			statusOptions: ['queued', 'sending', 'sent', 'acknowledged', 'failed', 'dead-letter'],
		}
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const params = {}
				if (this.status) params.status = this.status
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mdm/sync-queue'), { params })
				this.items = data.items || []
			} catch (e) {
				this.items = []
			} finally {
				this.loading = false
			}
		},
		canRetry(item) {
			return item.status === 'dead-letter' || item.status === 'failed'
		},
		async retry(item) {
			try {
				await axios.post(generateUrl('/apps/pipelinq/api/mdm/sync-queue/{id}/retry', { id: item.id }))
				showSuccess(t('pipelinq', 'Sync item re-queued'))
				this.fetchData()
			} catch (e) {
				showError(t('pipelinq', 'Could not re-queue the sync item'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mdm-sync {
	padding: 20px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		margin-bottom: 16px;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th, td {
			text-align: left;
			padding: 8px 12px;
			border-bottom: 1px solid var(--color-border);
		}
	}
}
</style>
