<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/archive/2026-06-22-pipelinq-werkplek-declarative/tasks.md#task-3 -->
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
import workspaceContextMixin from './workspaceContextMixin.js'

/**
 * WerkplekHeaderActions — the workspace dashboard's `actionsComponent`,
 * rendering the agent availability toggle in the standard page header
 * (`#header-actions`). Loads the initial availability from the aggregated
 * `GET /api/kcc-werkplek/state` endpoint; the toggle itself PUTs changes.
 *
 * @spec openspec/changes/archive/2026-06-22-pipelinq-werkplek-declarative/tasks.md#task-3
 */
/**
 * The caller the address names: the client of an active call, or the
 * client a previewed number looks up to; no client otherwise.
 *
 * @param {URLSearchParams} params The page address's query.
 * @return {Promise<{clientId: string, clientName: string}>} The caller.
 */
async function callerFromAddress(params) {
	if (params.get('call') === 'active') {
		return { clientId: (params.get('client') || '').trim(), clientName: '' }
	}
	const preview = (params.get('inCallPreview') || '').trim()
	if (preview === '') {
		return { clientId: '', clientName: '' }
	}
	const result = await screenPop(preview).catch(() => null)
	return callerOf(clientMatch(result))
}

/**
 * @param {?object} result The screen pop answer.
 * @return {(object|null)} The client it matched, or null.
 */
function clientMatch(result) {
	return (result && Array.isArray(result.matches))
		? result.matches.find((m) => m && m._matchType === 'client') || null
		: null
}

/**
 * @param {?object} match The matched client.
 * @return {{clientId: string, clientName: string}} Its id and name; empty without a match.
 */
function callerOf(match) {
	if (!match) {
		return { clientId: '', clientName: '' }
	}
	return { clientId: String(match.id || match.uuid || ''), clientName: String(match.name || '') }
}

export default {
	name: 'WerkplekHeaderActions',

	components: { NcButton, PhoneHangup, TimerOutline, WerkplekAgentStatus },

	mixins: [workspaceContextMixin],

	data() {
		return {
			isAvailable: false,
			now: Date.now(),
			ticker: null,
		}
	},

	computed: {
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
			const caller = await callerFromAddress(new URLSearchParams(window.location.search))
			if (caller.clientId !== '') {
				this.writeWorkspace({ selectedClient: caller.clientId, callActive: true, callIdentified: true, callStartedAt: new Date().toISOString() })
				this.writeWorkspace({ selectedClientName: caller.clientName || await this.clientNameOf(caller.clientId) })
			}
		},

		/**
		 * The client's name, for the page title "Gesprek met {name}"; '' when it cannot be read.
		 *
		 * @param {string} clientId The client's id.
		 * @return {Promise<string>}
		 * @spec openspec/specs/kcc-werkplek/spec.md#requirement-the-werkplek-in-a-call-names-the-caller
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
		 * @spec openspec/changes/archive/2026-06-14-kcc-werkplek/tasks.md#task-3.1
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
	background: var(--cn-board-notice-bg, #fff4de);
	color: var(--cn-board-notice-ink, #7a4f00);
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}
</style>
