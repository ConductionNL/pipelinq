<template>
	<div class="automation-list">
		<div class="automation-header">
			<h2>{{ t('pipelinq', 'Automations') }}</h2>
			<NcButton type="primary" @click="$router.push({ name: 'AutomationNew' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'New automation') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="automations.length === 0"
			:name="t('pipelinq', 'No automations yet')"
			:description="t('pipelinq', 'Create automations to trigger actions when CRM events occur.')">
			<template #icon>
				<RobotOutline :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" @click="$router.push({ name: 'AutomationNew' })">
					{{ t('pipelinq', 'Create first automation') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<table v-else class="automation-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Name') }}</th>
					<th>{{ t('pipelinq', 'Trigger') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Last run') }}</th>
					<th>{{ t('pipelinq', 'Runs') }}</th>
					<th>{{ t('pipelinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="automation in automations" :key="automation.id">
					<td>
						<router-link :to="{ name: 'AutomationDetail', params: { id: automation.id } }">
							{{ automation.name }}
						</router-link>
					</td>
					<td>{{ triggerLabel(automation.trigger) }}</td>
					<td>
						<NcCheckboxRadioSwitch
							:checked="automation.isActive"
							type="switch"
							@update:checked="toggleActive(automation)" />
					</td>
					<td>{{ automation.lastRun ? formatDate(automation.lastRun) : '-' }}</td>
					<td>{{ automation.runCount || 0 }}</td>
					<td>
						<NcButton type="tertiary"
							@click="$router.push({ name: 'AutomationHistory', params: { id: automation.id } })">
							<template #icon>
								<History :size="20" />
							</template>
						</NcButton>
						<NcButton type="tertiary" @click="confirmDelete(automation)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useObjectStore } from '../../store/store.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import History from 'vue-material-design-icons/History.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'AutomationList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcCheckboxRadioSwitch,
		Plus,
		RobotOutline,
		History,
		Delete,
	},
	data() {
		return {
			loading: false,
			automations: [],
		}
	},
	mounted() {
		this.fetchAutomations()
	},
	methods: {
		async fetchAutomations() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('automation')
				this.automations = result?.results || []
			} catch (e) {
				console.error('Failed to load automations', e)
			} finally {
				this.loading = false
			}
		},
		triggerLabel(trigger) {
			const labels = {
				lead_created: this.t('pipelinq', 'Lead created'),
				lead_stage_changed: this.t('pipelinq', 'Lead stage changed'),
				lead_assigned: this.t('pipelinq', 'Lead assigned'),
				lead_value_changed: this.t('pipelinq', 'Lead value changed'),
				contact_created: this.t('pipelinq', 'Contact created'),
				request_created: this.t('pipelinq', 'Request created'),
				request_status_changed: this.t('pipelinq', 'Request status changed'),
			}
			return labels[trigger] || trigger
		},
		formatDate(dateStr) {
			return new Date(dateStr).toLocaleString('nl-NL')
		},
		async toggleActive(automation) {
			const objectStore = useObjectStore()
			await objectStore.saveObject('automation', {
				...automation,
				isActive: !automation.isActive,
			})
			this.fetchAutomations()
		},
		async confirmDelete(automation) {
			if (confirm(this.t('pipelinq', 'Delete automation "{name}"?', { name: automation.name }))) {
				const objectStore = useObjectStore()
				await objectStore.deleteObject('automation', automation.id)
				this.fetchAutomations()
			}
		},
	},
}
</script>

<style scoped>
.automation-list {
	padding: 20px;
	max-width: 1200px;
}

.automation-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.automation-table {
	width: 100%;
	border-collapse: collapse;
}

.automation-table th,
.automation-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.automation-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}
</style>
