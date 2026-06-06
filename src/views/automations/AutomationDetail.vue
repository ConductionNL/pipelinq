<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div>
		<AutomationBuilder v-if="isNew" :id="'new'" />

		<CnDetailPage v-else
			:title="automation.name || t('pipelinq', 'Automation')"
			:subtitle="t('pipelinq', 'Automation')"
			:back-route="{ name: 'Automations' }"
			:back-label="t('pipelinq', 'Back to list')"
			:loading="loading"
			:sidebar="!loading"
			object-type="pipelinq_automation"
			:object-id="automationId">
			<template #actions>
				<NcButton type="primary" @click="onEdit">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete = true">
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
						<span>{{ automation.trigger || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Status') }}</label>
						<CnStatusBadge :status="automation.isActive ? 'active' : 'inactive'"
							:label="automation.isActive ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive')" />
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Last run') }}</label>
						<span>{{ automation.lastRun || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Run count') }}</label>
						<span>{{ automation.runCount || 0 }}</span>
					</div>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Trigger conditions')">
				<pre class="conditions-block">{{ formattedConditions }}</pre>
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Actions')">
				<ol v-if="actionList.length > 0" class="actions-list">
					<li v-for="(action, idx) in actionList" :key="idx">
						<strong>{{ action.type }}</strong>
						<span v-if="actionDetail(action)"> — {{ actionDetail(action) }}</span>
					</li>
				</ol>
				<p v-else>
					{{ t('pipelinq', 'No actions configured.') }}
				</p>
			</CnDetailCard>

			<CnDetailCard v-if="automation.webhookUrl" :title="t('pipelinq', 'Webhook')">
				<div class="info-grid">
					<div class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Webhook URL') }}</label>
						<span>{{ automation.webhookUrl }}</span>
					</div>
					<div v-if="automation.n8nWorkflowId" class="info-field">
						<label>{{ t('pipelinq', 'n8n workflow') }}</label>
						<span>{{ automation.n8nWorkflowId }}</span>
					</div>
				</div>
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Execution history')">
				<AutomationHistory :automation-id="automationId" :logs="historyLogs" />
			</CnDetailCard>
		</CnDetailPage>

		<NcDialog v-if="confirmDelete"
			:name="t('pipelinq', 'Delete automation')"
			:open="confirmDelete"
			@closing="confirmDelete = false">
			<p>{{ t('pipelinq', 'Are you sure? This cannot be undone.') }}</p>
			<template #actions>
				<NcButton @click="confirmDelete = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="doDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import AutomationBuilder from './AutomationBuilder.vue'
import AutomationHistory from './AutomationHistory.vue'

export default {
	name: 'AutomationDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		NcButton,
		NcDialog,
		AutomationBuilder,
		AutomationHistory,
	},
	props: {
		id: {
			type: String,
			required: true,
		},
	},
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},
	data() {
		return {
			automation: {},
			historyLogs: [],
			loading: true,
			confirmDelete: false,
		}
	},
	computed: {
		/**
		 * Whether this is a new (unsaved) automation.
		 *
		 * @return {boolean}
		 */
		isNew() {
			return this.id === 'new'
		},
		/**
		 * Stable automation id for child components.
		 *
		 * @return {string}
		 */
		automationId() {
			return this.id
		},
		/**
		 * Pretty-printed trigger conditions JSON.
		 *
		 * @return {string}
		 */
		formattedConditions() {
			try {
				return JSON.stringify(this.automation.triggerConditions || {}, null, 2)
			} catch (_e) {
				return '{}'
			}
		},
		/**
		 * Action list array (defensive against missing/null).
		 *
		 * @return {Array<object>}
		 */
		actionList() {
			return Array.isArray(this.automation.actions) ? this.automation.actions : []
		},
	},
	mounted() {
		if (!this.isNew) {
			this.fetchAutomation()
		} else {
			this.loading = false
		}
	},
	methods: {
		/**
		 * Fetch the automation + its history.
		 *
		 * @return {Promise<void>}
		 */
		async fetchAutomation() {
			this.loading = true
			try {
				const detail = await axios.get(generateUrl(`/apps/pipelinq/api/automations/${this.id}`))
				this.automation = detail.data || {}
				const hist = await axios.get(generateUrl(`/apps/pipelinq/api/automations/${this.id}/history`))
				this.historyLogs = (hist.data && hist.data.results) || []
			} catch (_e) {
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to load automation'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate to the builder edit view.
		 */
		onEdit() {
			this.$router.push({ name: 'AutomationEdit', params: { id: this.id } })
		},
		/**
		 * Delete the automation.
		 *
		 * @return {Promise<void>}
		 */
		async doDelete() {
			try {
				await axios.delete(generateUrl(`/apps/pipelinq/api/automations/${this.id}`))
				this.confirmDelete = false
				this.$router.push({ name: 'Automations' })
			} catch (_e) {
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to delete automation'))
			}
		},
		/**
		 * Render a one-line summary for an action.
		 *
		 * @param {object} action The action object.
		 * @return {string} Human label.
		 */
		actionDetail(action) {
			if (!action) {
				return ''
			}
			return action.assignee || action.message || action.text || action.url || action.tag || action.stage || action.decisionTableId || ''
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px 24px;
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
	margin-bottom: 4px;
}
.conditions-block {
	background: var(--color-background-dark);
	padding: 8px;
	border-radius: 6px;
	font-family: monospace;
}
.actions-list {
	margin: 0;
	padding-left: 20px;
}
</style>
