<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<CnDetailPage
		:title="automation.name || t('pipelinq', 'Automation')"
		:subtitle="t('pipelinq', 'CRM workflow automation')"
		:back-route="{ name: 'Automations' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!loading"
		object-type="pipelinq_automation"
		:object-id="automationId">
		<template #actions>
			<NcButton type="secondary" @click="edit">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton v-if="automation.isActive" type="secondary" :disabled="busy" @click="toggleActive(false)">
				{{ t('pipelinq', 'Deactivate') }}
			</NcButton>
			<NcButton v-else type="primary" :disabled="busy" @click="toggleActive(true)">
				{{ t('pipelinq', 'Activate') }}
			</NcButton>
			<NcButton type="error" :disabled="busy" @click="showDelete = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Automation information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Name') }}</label>
					<span>{{ automation.name || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Trigger') }}</label>
					<span>{{ triggerLabel }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge
						:status="automation.isActive ? 'active' : 'inactive'"
						:label="automation.isActive ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive')" />
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Last run') }}</label>
					<span>{{ formatDate(automation.lastRun) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Run count') }}</label>
					<span>{{ automation.runCount || 0 }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Trigger conditions')">
			<p v-if="!hasConditions" class="muted">
				{{ t('pipelinq', 'No conditions — this automation fires on every matching trigger.') }}
			</p>
			<ul v-else class="condition-list">
				<li v-for="(value, field) in automation.triggerConditions" :key="field">
					<strong>{{ field }}</strong>: {{ formatValue(value) }}
				</li>
			</ul>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Actions')">
			<p v-if="!hasActions" class="muted">
				{{ t('pipelinq', 'No actions configured.') }}
			</p>
			<ol v-else class="action-list">
				<li v-for="(action, index) in automation.actions" :key="index">
					{{ actionLabel(action.type) }}
				</li>
			</ol>
		</CnDetailCard>

		<CnDetailCard v-if="automation.webhookUrl" :title="t('pipelinq', 'Webhook')">
			<div class="info-field info-field--wide">
				<label>{{ t('pipelinq', 'Webhook URL') }}</label>
				<span class="mono">{{ automation.webhookUrl }}</span>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Execution history')">
			<AutomationHistory :automation-id="automationId" :logs="history" :loading="historyLoading" />
		</CnDetailCard>

		<NcDialog
			v-if="showDelete"
			:name="t('pipelinq', 'Delete automation')"
			@closing="showDelete = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete this automation? This cannot be undone.') }}</p>
			<template #actions>
				<NcButton @click="showDelete = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="busy" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'
import AutomationHistory from './AutomationHistory.vue'

const TRIGGER_LABELS = {
	lead_created: 'Lead created',
	lead_stage_changed: 'Lead stage changed',
	lead_assigned: 'Lead assigned',
	contact_created: 'Contact created',
	request_created: 'Request created',
	request_status_changed: 'Request status changed',
	marketing_segment_match: 'Marketing segment match',
}

const ACTION_LABELS = {
	assign_lead: 'Assign lead',
	move_stage: 'Move stage',
	send_notification: 'Send notification',
	add_note: 'Add note',
	fire_webhook: 'Fire webhook',
	update_tag: 'Update tag',
	apply_decision: 'Apply decision',
}

export default {
	name: 'AutomationDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		NcButton,
		NcDialog,
		AutomationHistory,
	},
	data() {
		return {
			automation: {},
			history: [],
			loading: true,
			historyLoading: true,
			busy: false,
			showDelete: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		automationId() {
			return this.$route.params.id
		},
		triggerLabel() {
			return this.t('pipelinq', TRIGGER_LABELS[this.automation.trigger] || this.automation.trigger || '-')
		},
		hasConditions() {
			const c = this.automation.triggerConditions
			return c && typeof c === 'object' && Object.keys(c).length > 0
		},
		hasActions() {
			return Array.isArray(this.automation.actions) && this.automation.actions.length > 0
		},
	},
	async created() {
		await this.load()
	},
	methods: {
		/**
		 * Load the automation and its execution history.
		 */
		async load() {
			this.loading = true
			try {
				this.automation = await this.objectStore.fetchObject('automation', this.automationId) || {}
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to load automation.'))
			} finally {
				this.loading = false
			}
			await this.loadHistory()
		},
		/**
		 * Load the automationLog entries linked to this automation.
		 */
		async loadHistory() {
			this.historyLoading = true
			try {
				await this.objectStore.fetchCollection('automationLog', { automation: this.automationId, _limit: 200 })
				const rows = this.objectStore.getCollection('automationLog')?.results || []
				this.history = rows.slice().sort((a, b) => String(b.triggeredAt || '').localeCompare(String(a.triggeredAt || '')))
			} catch (e) {
				this.history = []
			} finally {
				this.historyLoading = false
			}
		},
		/**
		 * Navigate to the builder to edit this automation.
		 */
		edit() {
			this.$router.push({ name: 'AutomationEdit', params: { id: this.automationId } })
		},
		/**
		 * Activate or deactivate the automation.
		 *
		 * @param {boolean} active The desired active state.
		 */
		async toggleActive(active) {
			this.busy = true
			try {
				await this.objectStore.saveObject('automation', { ...this.automation, isActive: active })
				showSuccess(active ? this.t('pipelinq', 'Automation activated.') : this.t('pipelinq', 'Automation deactivated.'))
				await this.load()
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to update automation.'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Delete the automation after confirmation.
		 */
		async confirmDelete() {
			this.busy = true
			try {
				await this.objectStore.deleteObject('automation', this.automationId)
				showSuccess(this.t('pipelinq', 'Automation deleted.'))
				this.$router.push({ name: 'Automations' })
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to delete automation.'))
			} finally {
				this.busy = false
				this.showDelete = false
			}
		},
		/**
		 * Human-readable label for an action type.
		 *
		 * @param {string} type The action type.
		 * @return {string} The localized label.
		 */
		actionLabel(type) {
			return this.t('pipelinq', ACTION_LABELS[type] || type || '-')
		},
		/**
		 * Format a stored value for display.
		 *
		 * @param {*} value The condition value.
		 * @return {string} The display string.
		 */
		formatValue(value) {
			if (value && typeof value === 'object') {
				return JSON.stringify(value)
			}
			return String(value)
		},
		/**
		 * Format an ISO date for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The display date.
		 */
		formatDate(value) {
			if (!value) {
				return this.t('pipelinq', 'Never')
			}
			return new Date(value).toLocaleString()
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px;
}
.info-field {
	display: flex;
	flex-direction: column;
}
.info-field--wide {
	grid-column: 1 / -1;
}
.info-field label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}
.condition-list,
.action-list {
	margin: 0;
	padding-left: 20px;
}
.muted {
	color: var(--color-text-maxcontrast);
}
.mono {
	font-family: monospace;
	word-break: break-all;
}
</style>
