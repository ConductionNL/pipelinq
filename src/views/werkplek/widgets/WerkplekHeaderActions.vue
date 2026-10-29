<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3 -->
<template>
	<div class="werkplek-header-actions">
		<WerkplekAgentStatus
			:isAvailable="isAvailable"
			:inCall="callActive"
			@update:isAvailable="isAvailable = $event"
			@saved="onSaved" />
		<template v-if="callActive">
			<span
				class="werkplek-header-actions__timer"
				role="timer"
				:aria-label="t('pipelinq', 'Call duration')"
				data-testid="werkplek-call-timer">
				<TimerOutline :size="16" aria-hidden="true" />
				{{ elapsed }}
			</span>
			<NcButton variant="primary" data-testid="werkplek-call-end" @click="endCall">
				<template #icon>
					<PhoneHangup :size="20" />
				</template>
				{{ t('pipelinq', 'End and finish') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import PhoneHangup from 'vue-material-design-icons/PhoneHangup.vue'
import TimerOutline from 'vue-material-design-icons/TimerOutline.vue'
import WerkplekAgentStatus from '../../../components/werkplek/WerkplekAgentStatus.vue'
import { screenPop } from '../../../services/ctiApi.js'
import { useObjectStore } from '../../../store/modules/object.js'

/**
 * WerkplekHeaderActions — the workspace dashboard's `actionsComponent`,
 * rendering the agent availability toggle in the standard page header
 * (`#header-actions`). Loads the initial availability from the aggregated
 * `GET /api/kcc-werkplek/state` endpoint; the toggle itself PUTs changes.
 *
 * @spec openspec/changes/pipelinq-werkplek-declarative/tasks.md#task-3
 */
export default {
	name: 'WerkplekHeaderActions',

	components: { NcButton, PhoneHangup, TimerOutline, WerkplekAgentStatus },

	inject: {
		cnWorkspaceContext: { default: null },
	},

	data() {
		return {
			isAvailable: false,
			now: Date.now(),
			ticker: null,
		}
	},

	computed: {
		/**
		 * The page's workspace bag; the provided ref may arrive unwrapped or as `{ value }`.
		 *
		 * @return {object|null}
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		workspace() {
			const c = this.cnWorkspaceContext
			if (!c || typeof c !== 'object') {
				return null
			}
			return 'value' in c ? c.value : c
		},

		/**
		 * Whether a call is on the line: the page shows the in-call cards and this header.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		callActive() {
			return Boolean(this.workspace && this.workspace.callActive === true)
		},

		/**
		 * How long the call has run, as m:ss.
		 *
		 * @return {string}
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		elapsed() {
			const started = Date.parse((this.workspace && this.workspace.callStartedAt) || '')
			const seconds = Number.isNaN(started) ? 0 : Math.max(0, Math.floor((this.now - started) / 1000))
			const minutes = Math.floor(seconds / 60)
			return String(minutes).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0')
		},
	},

	/**
	 * Read the availability and any call the address names, and tick the call timer.
	 *
	 * @spec openspec/specs/kcc-werkplek/spec.md
	 */
	created() {
		this.fetchAvailability()
		this.startCallFromAddress()
		this.ticker = setInterval(() => {
			this.now = Date.now()
		}, 1000)
	},

	beforeUnmount() {
		clearInterval(this.ticker)
	},

	methods: {
		/**
		 * Put the page in the in-call state the address names: `client=<id>&call=active`
		 * for a call the agent picked up, or `inCallPreview=<number>`, which looks the
		 * number up as a real call would, so the in-call page can be seen without a
		 * line. The preview only reads. (It is not `callPreview`, which shows the
		 * incoming-call card.)
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		async startCallFromAddress() {
			const params = new URLSearchParams(window.location.search)
			let clientId = ''
			let clientName = ''
			if (params.get('call') === 'active') {
				clientId = (params.get('client') || '').trim()
			} else if ((params.get('inCallPreview') || '').trim() !== '') {
				const result = await screenPop(params.get('inCallPreview').trim()).catch(() => null)
				const match = (result && Array.isArray(result.matches))
					? result.matches.find((m) => m && m._matchType === 'client')
					: null
				clientId = match ? String(match.id || match.uuid || '') : ''
				clientName = match ? String(match.name || '') : ''
			}
			if (clientId !== '') {
				this.writeWorkspace({ selectedClient: clientId, callActive: true, callIdentified: true, callStartedAt: new Date().toISOString() })
				this.writeWorkspace({ selectedClientName: clientName || await this.clientNameOf(clientId) })
			}
		},

		/**
		 * The client's name, for the page title "Gesprek met {name}"; '' when it cannot be read.
		 *
		 * @param {string} clientId The client's id.
		 * @return {Promise<string>}
		 * @spec openspec/changes/werkplek-call-names-the-caller/specs/kcc-werkplek/spec.md#requirement-the-werkplek-in-a-call-names-the-caller
		 */
		async clientNameOf(clientId) {
			try {
				const client = await useObjectStore().fetchObject('client', clientId)
				return client && client.name ? String(client.name) : ''
			} catch {
				return ''
			}
		},

		/**
		 * End the call: the page goes back to the dashboard, keeping the client in focus.
		 *
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		endCall() {
			this.writeWorkspace({ callActive: false, callIdentified: false, callStartedAt: '' })
		},

		/**
		 * Merge keys into the page's workspace bag.
		 *
		 * @param {object} values The keys to write.
		 * @spec openspec/specs/kcc-werkplek/spec.md
		 */
		writeWorkspace(values) {
			const holder = this.cnWorkspaceContext
			if (!holder || typeof holder !== 'object') {
				return
			}
			if ('value' in holder) {
				holder.value = { ...(holder.value || {}), ...values }
			} else {
				Object.assign(holder, values)
			}
		},

		/**
		 * The availability is saved: the dashboard header line that says
		 * whether the agent is available reads it again.
		 *
		 * @return {void}
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.1
		 */
		onSaved() {
			emit('cn:dashboard:description-refresh', {})
		},

		/**
		 * Load the agent's current availability so the toggle reflects state on
		 * first paint. Failures leave it at the default (unavailable).
		 *
		 * @return {Promise<void>}
		 */
		async fetchAvailability() {
			try {
				const res = await axios.get(
					generateUrl('/apps/pipelinq/api/kcc-werkplek/state'),
				)
				const profile = (res.data && res.data.agentProfile) || {}
				this.isAvailable = Boolean(profile.isAvailable)
			} catch (e) {
				console.warn('[WerkplekHeaderActions] availability fetch failed', e)
			}
		},
	},
}
</script>

<style scoped>
.werkplek-header-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}

/* The call's running time as its own amber chip (PqWerkplekKlant). */
.werkplek-header-actions__timer {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	min-height: 40px;
	padding: 0 14px;
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-warning-hover, var(--color-background-dark));
	color: var(--color-warning-text, var(--color-main-text));
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}
</style>
