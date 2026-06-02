<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  KCC Werkplek — unified agent workspace.

  Three-panel layout: inbox (assigned requests + open tasks) | active
  contactmoment registration | agent availability + queue overview.
  Aggregated state is fetched from the server-authoritative
  `/api/kcc-werkplek/state` endpoint (no client-side N+1 fan-out); the
  contactmoment is written through OpenRegister's generic object API with the
  `agent` left blank so the backend/owner records the registering user.

  Inline knowledge-base search (proposal §3) is intentionally NOT built here:
  the kennisbank schemas were migrated out of pipelinq to an external XWiki
  leaf (migrate-kennisbank-to-xwiki-leaf), so there is no in-app
  `kennisartikel` store or feedback service to consult. See tasks.md §3.4.

  @spec openspec/changes/kcc-werkplek/tasks.md#task-4
-->
<template>
	<div class="kcc-werkplek">
		<div class="kcc-werkplek__header">
			<h2 class="kcc-werkplek__title">
				{{ t('pipelinq', 'KCC Werkplek') }}
			</h2>
			<div class="kcc-werkplek__status">
				<span class="kcc-werkplek__workload">
					{{ t('pipelinq', 'Workload') }}: {{ workload }}
				</span>
				<NcButton
					:type="isAvailable ? 'success' : 'secondary'"
					:disabled="togglingAvailability"
					@click="toggleAvailability">
					{{ isAvailable ? t('pipelinq', 'Available') : t('pipelinq', 'Unavailable') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" class="kcc-werkplek__loading" :size="44" />

		<div v-else-if="loadError" class="kcc-werkplek__error">
			<p>{{ loadError }}</p>
			<NcButton @click="fetchState">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</div>

		<div v-else class="kcc-werkplek__panels">
			<!-- Inbox panel -->
			<section class="kcc-panel kcc-panel--inbox">
				<h3 class="kcc-panel__heading">
					{{ t('pipelinq', 'Inbox') }}
				</h3>

				<div
					v-if="assignedRequests.length === 0 && openTasks.length === 0"
					class="kcc-panel__empty">
					{{ t('pipelinq', 'No open items') }}
				</div>

				<template v-else>
					<h4 class="kcc-panel__subheading">
						{{ t('pipelinq', 'Requests') }} ({{ assignedRequests.length }})
					</h4>
					<ul class="kcc-list">
						<li
							v-for="req in sortedRequests"
							:key="req.id"
							class="kcc-list__item"
							:class="{ 'kcc-list__item--selected': selectedItem && selectedItem.id === req.id }"
							tabindex="0"
							@click="selectRequest(req)"
							@keydown.enter="selectRequest(req)">
							<span class="kcc-list__title">{{ req.title || t('pipelinq', 'Untitled') }}</span>
							<span class="kcc-list__meta">
								<span v-if="req.channel" class="kcc-badge">{{ req.channel }}</span>
								<span class="kcc-badge" :style="{ color: priorityColor(req.priority) }">
									{{ req.priority }}
								</span>
							</span>
						</li>
					</ul>

					<h4 class="kcc-panel__subheading">
						{{ t('pipelinq', 'Tasks') }} ({{ openTasks.length }})
					</h4>
					<ul class="kcc-list">
						<li
							v-for="task in sortedTasks"
							:key="task.id"
							class="kcc-list__item"
							tabindex="0"
							@click="selectTask(task)"
							@keydown.enter="selectTask(task)">
							<span class="kcc-list__title">{{ task.subject || t('pipelinq', 'Untitled') }}</span>
							<span class="kcc-list__meta">
								<span v-if="task.type" class="kcc-badge">{{ task.type }}</span>
								<span
									v-if="task.deadline"
									class="kcc-list__deadline"
									:class="{ 'kcc-list__deadline--overdue': isOverdue(task.deadline) }">
									{{ formatDate(task.deadline) }}
								</span>
							</span>
						</li>
					</ul>
				</template>
			</section>

			<!-- Contactmoment registration panel -->
			<section class="kcc-panel kcc-panel--register">
				<h3 class="kcc-panel__heading">
					{{ t('pipelinq', 'Register contactmoment') }}
				</h3>

				<div class="kcc-field">
					<label class="kcc-field__label" for="kcc-channel">{{ t('pipelinq', 'Channel') }}</label>
					<NcSelect
						input-id="kcc-channel"
						:value="form.channel"
						:options="channelOptions"
						:input-label="t('pipelinq', 'Channel')"
						@input="form.channel = $event" />
				</div>

				<CallTimer
					v-if="form.channel === 'telefoon'"
					ref="callTimer"
					class="kcc-field"
					@stopped="onTimerStopped" />

				<div class="kcc-field">
					<NcTextField
						:value.sync="form.subject"
						:label="t('pipelinq', 'Subject')"
						:required="true" />
				</div>

				<div class="kcc-field">
					<NcTextArea
						:value.sync="form.summary"
						:label="t('pipelinq', 'Summary')" />
				</div>

				<div class="kcc-field">
					<label class="kcc-field__label" for="kcc-outcome">{{ t('pipelinq', 'Outcome') }}</label>
					<NcSelect
						input-id="kcc-outcome"
						:value="form.outcome"
						:options="outcomeOptions"
						:input-label="t('pipelinq', 'Outcome')"
						@input="form.outcome = $event" />
				</div>

				<div class="kcc-field__actions">
					<NcButton
						type="primary"
						:disabled="registering || !canRegister"
						@click="registerContactmoment">
						{{ t('pipelinq', 'Register') }}
					</NcButton>
					<NcButton :disabled="registering" @click="resetForm">
						{{ t('pipelinq', 'Clear') }}
					</NcButton>
				</div>

				<p v-if="registerError" class="kcc-panel__inline-error">
					{{ registerError }}
				</p>
				<p v-if="registerSuccess" class="kcc-panel__inline-success">
					{{ t('pipelinq', 'Contactmoment registered') }}
				</p>
			</section>

			<!-- Queue overview panel -->
			<section class="kcc-panel kcc-panel--queues">
				<h3 class="kcc-panel__heading">
					{{ t('pipelinq', 'Queues') }}
				</h3>
				<div v-if="queueRows.length === 0" class="kcc-panel__empty">
					{{ t('pipelinq', 'No open items') }}
				</div>
				<ul v-else class="kcc-list">
					<li v-for="q in queueRows" :key="q.name" class="kcc-list__item kcc-list__item--static">
						<span class="kcc-list__title">{{ q.name }}</span>
						<span class="kcc-badge">{{ q.count }}</span>
					</li>
				</ul>
			</section>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField, NcTextArea } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import CallTimer from '../../components/CallTimer.vue'
import { useObjectStore } from '../../store/modules/object.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }
const PRIORITY_COLORS = {
	urgent: 'var(--color-error)',
	high: 'var(--color-warning)',
	normal: 'var(--color-text-maxcontrast)',
	low: 'var(--color-text-maxcontrast)',
}

export default {
	name: 'KccWerkplek',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		NcTextArea,
		CallTimer,
	},
	data() {
		return {
			loading: false,
			loadError: null,
			togglingAvailability: false,
			registering: false,
			registerError: null,
			registerSuccess: false,
			isAvailable: false,
			workload: 0,
			assignedRequests: [],
			openTasks: [],
			queueCounts: {},
			selectedItem: null,
			form: this.emptyForm(),
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1
		 */
		objectStore() {
			return useObjectStore()
		},
		channelOptions() {
			return ['telefoon', 'email', 'balie', 'chat', 'social', 'brief']
		},
		outcomeOptions() {
			return ['afgehandeld', 'doorverbonden', 'terugbelverzoek', 'vervolgactie']
		},
		canRegister() {
			return !!this.form.subject && !!this.form.channel
		},
		sortedRequests() {
			return [...this.assignedRequests].sort(
				(a, b) => (PRIORITY_ORDER[a.priority] ?? 2) - (PRIORITY_ORDER[b.priority] ?? 2),
			)
		},
		sortedTasks() {
			return [...this.openTasks].sort(
				(a, b) => (PRIORITY_ORDER[a.priority] ?? 2) - (PRIORITY_ORDER[b.priority] ?? 2),
			)
		},
		queueRows() {
			return Object.entries(this.queueCounts)
				.map(([name, count]) => ({ name, count }))
				.sort((a, b) => b.count - a.count)
		},
	},
	created() {
		this.fetchState()
	},
	methods: {
		emptyForm() {
			return {
				channel: 'telefoon',
				subject: '',
				summary: '',
				outcome: null,
				duration: null,
				request: null,
				client: null,
			}
		},

		/**
		 * Fetch the aggregated workspace state from the backend.
		 *
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1
		 */
		async fetchState() {
			this.loading = true
			this.loadError = null
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/kcc-werkplek/state'))
				this.assignedRequests = data.assignedRequests || []
				this.openTasks = data.openTasks || []
				this.queueCounts = data.queueCounts || {}
				this.workload = data.workload || 0
				this.isAvailable = !!(data.agentProfile && data.agentProfile.isAvailable)
			} catch (err) {
				console.error('KccWerkplek: failed to load state', err)
				this.loadError = t('pipelinq', 'Failed to load the workspace')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Toggle the agent's own availability (server derives the user).
		 *
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1
		 */
		async toggleAvailability() {
			const next = !this.isAvailable
			this.togglingAvailability = true
			// Optimistic flip, reverted on failure.
			this.isAvailable = next
			try {
				const { data } = await axios.put(
					generateUrl('/apps/pipelinq/api/kcc-werkplek/availability'),
					{ isAvailable: next },
				)
				this.isAvailable = !!(data && data.isAvailable)
			} catch (err) {
				console.error('KccWerkplek: failed to set availability', err)
				this.isAvailable = !next
				showError(t('pipelinq', 'Could not update your availability'))
			} finally {
				this.togglingAvailability = false
			}
		},

		/**
		 * Load a request into the contactmoment form.
		 *
		 * @param {object} req The selected request row.
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1
		 */
		selectRequest(req) {
			this.selectedItem = req
			this.form.subject = req.title || ''
			this.form.request = req.id || null
			if (req.channel) {
				this.form.channel = req.channel
			}
		},

		/**
		 * Load a task into the contactmoment form.
		 *
		 * @param {object} task The selected task row.
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1
		 */
		selectTask(task) {
			this.selectedItem = task
			this.form.subject = task.subject || ''
		},

		/**
		 * Capture the ISO-8601 call duration emitted by the timer.
		 *
		 * @param {string} isoDuration The ISO-8601 duration (e.g. PT3M12S).
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
		 */
		onTimerStopped(isoDuration) {
			this.form.duration = isoDuration
		},

		/**
		 * Register the contactmoment via OpenRegister's generic object API.
		 * The `agent` field is intentionally NOT sent — the backend/owner
		 * records the authenticated user (ADR-005, IDOR-safe).
		 *
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
		 */
		async registerContactmoment() {
			if (!this.canRegister) {
				return
			}
			const config = this.objectStore.objectTypeRegistry.contactmoment
			if (!config) {
				showError(t('pipelinq', 'Contactmoment is not configured'))
				return
			}

			this.registering = true
			this.registerError = null
			this.registerSuccess = false

			const body = {
				subject: this.form.subject,
				summary: this.form.summary,
				channel: this.form.channel,
				contactedAt: new Date().toISOString(),
			}
			if (this.form.outcome) {
				body.outcome = this.form.outcome
			}
			if (this.form.duration) {
				body.duration = this.form.duration
			}
			if (this.form.request) {
				body.request = this.form.request
			}

			try {
				await axios.post(
					generateUrl('/apps/openregister/api/objects/' + config.register + '/' + config.schema),
					body,
				)
				this.registerSuccess = true
				this.resetForm()
			} catch (err) {
				console.error('KccWerkplek: failed to register contactmoment', err)
				this.registerError = t('pipelinq', 'Could not register the contactmoment')
			} finally {
				this.registering = false
			}
		},

		/**
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
		 */
		resetForm() {
			this.form = this.emptyForm()
			this.selectedItem = null
			if (this.$refs.callTimer && typeof this.$refs.callTimer.reset === 'function') {
				this.$refs.callTimer.reset()
			}
		},

		priorityColor(priority) {
			return PRIORITY_COLORS[priority] || PRIORITY_COLORS.normal
		},

		isOverdue(deadline) {
			if (!deadline) {
				return false
			}
			return new Date(deadline).getTime() < Date.now()
		},

		formatDate(value) {
			if (!value) {
				return ''
			}
			try {
				return new Date(value).toLocaleDateString()
			} catch {
				return value
			}
		},
	},
}
</script>

<style scoped>
.kcc-werkplek {
	padding: 20px;
}

.kcc-werkplek__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 16px;
}

.kcc-werkplek__title {
	margin: 0;
}

.kcc-werkplek__status {
	display: flex;
	align-items: center;
	gap: 12px;
}

.kcc-werkplek__workload {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.kcc-werkplek__loading {
	margin-top: 60px;
}

.kcc-werkplek__error {
	margin-top: 60px;
	text-align: center;
	color: var(--color-error);
}

.kcc-werkplek__error p {
	margin-bottom: 12px;
}

.kcc-werkplek__panels {
	display: grid;
	grid-template-columns: 320px 1fr 280px;
	gap: 16px;
	align-items: start;
}

@media (max-width: 1024px) {
	.kcc-werkplek__panels {
		grid-template-columns: 1fr;
	}
}

.kcc-panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.kcc-panel__heading {
	margin: 0 0 12px;
	font-size: 16px;
}

.kcc-panel__subheading {
	margin: 16px 0 8px;
	font-size: 13px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
}

.kcc-panel__empty {
	padding: 24px 8px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.kcc-panel__inline-error {
	margin-top: 12px;
	color: var(--color-error);
}

.kcc-panel__inline-success {
	margin-top: 12px;
	color: var(--color-success);
}

.kcc-list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.kcc-list__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.kcc-list__item--static {
	cursor: default;
}

.kcc-list__item:hover:not(.kcc-list__item--static),
.kcc-list__item:focus-visible {
	background: var(--color-background-hover);
	outline: none;
}

.kcc-list__item--selected {
	border-color: var(--color-primary-element);
	box-shadow: inset 0 0 0 1px var(--color-primary-element);
}

.kcc-list__title {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.kcc-list__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-shrink: 0;
}

.kcc-badge {
	font-size: 11px;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
}

.kcc-list__deadline {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.kcc-list__deadline--overdue {
	color: var(--color-error);
	font-weight: 600;
}

.kcc-field {
	margin-bottom: 12px;
}

.kcc-field__label {
	display: block;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.kcc-field__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
