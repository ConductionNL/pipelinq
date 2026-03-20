<template>
	<div class="task-detail">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="task">
			<div class="task-detail__header">
				<router-link :to="{ name: 'Tasks' }">
					{{ t('pipelinq', 'Back to Tasks') }}
				</router-link>
				<div class="task-detail__actions">
					<NcButton
						v-if="task.status === 'open'"
						type="primary"
						@click="claimTask">
						{{ t('pipelinq', 'Claim task') }}
					</NcButton>
					<NcButton
						v-if="task.status === 'in_behandeling'"
						type="primary"
						@click="showCompleteDialog = true">
						{{ t('pipelinq', 'Complete') }}
					</NcButton>
					<NcButton
						v-if="task.status === 'in_behandeling' && task.type === 'terugbelverzoek'"
						type="secondary"
						@click="logAttempt">
						{{ t('pipelinq', 'Log attempt (not reached)') }}
					</NcButton>
					<NcButton
						v-if="task.status === 'afgerond' || task.status === 'verlopen'"
						type="secondary"
						@click="reopenTask">
						{{ t('pipelinq', 'Reopen') }}
					</NcButton>
				</div>
			</div>

			<div class="task-detail__badges">
				<span class="type-badge" :class="'type-badge--' + task.type">
					{{ getTypeLabel(task.type) }}
				</span>
				<span class="status-badge" :class="'status-badge--' + task.status">
					{{ task.status }}
				</span>
				<span class="priority-badge" :style="{ color: getPriorityColor(task.priority) }">
					{{ task.priority }}
				</span>
			</div>

			<h2>{{ task.subject }}</h2>

			<div v-if="task.description" class="task-detail__description">
				<p>{{ task.description }}</p>
			</div>

			<div class="task-detail__info">
				<div class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Assigned to') }}</span>
					<span>{{ task.assignee }} ({{ task.assigneeType }})</span>
				</div>
				<div class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Deadline') }}</span>
					<span :class="{ 'deadline--urgent': isOverdue }">{{ formatDate(task.deadline) }}</span>
				</div>
				<div v-if="task.preferredTimeSlot" class="info-row info-row--highlight">
					<span class="info-label">{{ t('pipelinq', 'Preferred callback time') }}</span>
					<span>{{ task.preferredTimeSlot }}</span>
				</div>
				<div v-if="task.callbackPhone" class="info-row info-row--highlight">
					<span class="info-label">{{ t('pipelinq', 'Callback phone') }}</span>
					<span>{{ task.callbackPhone }}</span>
				</div>
				<div class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Created by') }}</span>
					<span>{{ task.createdBy }}</span>
				</div>
				<div v-if="task.attempts > 0" class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Callback attempts') }}</span>
					<span>{{ task.attempts }}</span>
				</div>
				<div v-if="task.result" class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Result') }}</span>
					<span>{{ task.result }}</span>
				</div>
			</div>

			<!-- Complete dialog -->
			<div v-if="showCompleteDialog" class="complete-dialog">
				<h3>{{ t('pipelinq', 'Complete task') }}</h3>
				<NcTextField
					:value.sync="resultText"
					:label="t('pipelinq', 'Result / notes')"
					:multiline="true" />
				<div class="complete-dialog__actions">
					<NcButton type="tertiary" @click="showCompleteDialog = false">
						{{ t('pipelinq', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" @click="completeTask">
						{{ t('pipelinq', 'Mark as completed') }}
					</NcButton>
				</div>
			</div>
		</template>

		<NcEmptyContent
			v-else
			:name="t('pipelinq', 'Task not found')" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'TaskDetail',
	components: { NcButton, NcLoadingIcon, NcEmptyContent, NcTextField },
	props: {
		taskId: { type: String, required: true },
	},
	data() {
		return {
			task: null,
			loading: true,
			showCompleteDialog: false,
			resultText: '',
		}
	},
	computed: {
		isOverdue() {
			if (!this.task || !this.task.deadline || this.task.status === 'afgerond') return false
			return new Date(this.task.deadline) < new Date()
		},
	},
	mounted() { this.fetchTask() },
	methods: {
		async fetchTask() {
			this.loading = true
			try { this.task = null } finally { this.loading = false }
		},
		async claimTask() {
			showSuccess(t('pipelinq', 'Task claimed'))
		},
		async completeTask() {
			showSuccess(t('pipelinq', 'Task completed'))
			this.showCompleteDialog = false
		},
		async logAttempt() {
			showSuccess(t('pipelinq', 'Attempt logged'))
		},
		async reopenTask() {
			showSuccess(t('pipelinq', 'Task reopened'))
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('nl-NL', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
		},
		getTypeLabel(type) {
			return { terugbelverzoek: t('pipelinq', 'Callback'), opvolgtaak: t('pipelinq', 'Follow-up'), informatievraag: t('pipelinq', 'Info request') }[type] || type
		},
		getPriorityColor(priority) {
			return { hoog: '#e53e3e', normaal: '#3182ce', laag: '#718096' }[priority] || '#718096'
		},
	},
}
</script>

<style scoped>
.task-detail { padding: 20px; max-width: 800px; margin: 0 auto; }
.task-detail__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.task-detail__actions { display: flex; gap: 8px; }
.task-detail__badges { display: flex; gap: 8px; margin-bottom: 12px; }
.task-detail__description { margin: 12px 0; padding: 12px; background: var(--color-background-dark); border-radius: var(--border-radius); }
.task-detail__info { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; }
.info-row { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--color-border); }
.info-row--highlight { background: var(--color-primary-element-light); padding: 8px 12px; border-radius: var(--border-radius); border-bottom: none; }
.info-label { font-weight: 600; min-width: 180px; color: var(--color-text-lighter); }
.deadline--urgent { color: #e53e3e; font-weight: 600; }
.complete-dialog { margin-top: 20px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.complete-dialog__actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
.type-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; font-weight: 600; }
.type-badge--terugbelverzoek { background: #bee3f8; color: #2a4365; }
.type-badge--opvolgtaak { background: #fefcbf; color: #744210; }
.type-badge--informatievraag { background: #c6f6d5; color: #22543d; }
.status-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; }
.status-badge--open { background: var(--color-primary-element-light); }
.status-badge--in_behandeling { background: var(--color-warning); color: #000; }
.status-badge--afgerond { background: var(--color-success); color: #fff; }
.status-badge--verlopen { background: #e53e3e; color: #fff; }
.priority-badge { font-size: 0.75em; font-weight: 700; text-transform: uppercase; }
</style>
