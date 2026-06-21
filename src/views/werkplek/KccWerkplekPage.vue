<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1 -->
<template>
	<div class="kcc-werkplek-page">
		<header class="kcc-werkplek-page__header">
			<div class="kcc-werkplek-page__header-left">
				<h2 class="kcc-werkplek-page__title">
					{{ t('pipelinq', 'Customer Support') }}
				</h2>
				<NcSelect
					v-if="hasQueueOptions"
					v-model="selectedQueue"
					:input-label="t('pipelinq', 'Queue')"
					:options="queueOptions"
					label="label"
					:reduce="o => o.value"
					:clearable="true" />
			</div>
			<div class="kcc-werkplek-page__header-right">
				<WerkplekAgentStatus
					:is-available="agentProfile.isAvailable"
					@update:isAvailable="onAvailabilityChange" />
			</div>
		</header>

		<NcNoteCard v-if="error" type="error" class="kcc-werkplek-page__error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="kcc-werkplek-page__loading">
			<NcLoadingIcon :name="t('pipelinq', 'Loading workspace...')" />
		</div>

		<div v-else class="kcc-werkplek-page__layout">
			<aside class="kcc-werkplek-page__panel kcc-werkplek-page__panel--inbox">
				<WerkplekInbox
					:requests="filteredRequests"
					:tasks="openTasks"
					@select-item="onSelectItem" />
			</aside>

			<main class="kcc-werkplek-page__panel kcc-werkplek-page__panel--center">
				<WerkplekContactmomentPanel
					:context="activeContext"
					@saved="onPanelSaved" />
			</main>

			<aside class="kcc-werkplek-page__panel kcc-werkplek-page__panel--kennis">
				<WerkplekKennisSearch />
			</aside>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import WerkplekAgentStatus from '../../components/werkplek/WerkplekAgentStatus.vue'
import WerkplekInbox from '../../components/werkplek/WerkplekInbox.vue'
import WerkplekContactmomentPanel from '../../components/werkplek/WerkplekContactmomentPanel.vue'
import WerkplekKennisSearch from '../../components/werkplek/WerkplekKennisSearch.vue'

/**
 * KCC Werkplek — unified agent workspace (three-panel layout).
 *
 * Fetches the aggregated workspace state from the bespoke
 * `GET /api/kcc-werkplek/state` endpoint on `created()` so the inbox,
 * agent profile, and queue lists are filled in a single round-trip
 * (REQ-KWP-010). Distributes the resulting state to the three child
 * panels via props/emits. A queue selector in the header filters the
 * inbox client-side. Every async call is wrapped in try/catch and any
 * failure surfaces inline via NcNoteCard while preserving the previous
 * state — agents never see a blank screen because of a transient error.
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1
 */
export default {
	name: 'KccWerkplekPage',

	components: {
		NcSelect,
		NcLoadingIcon,
		NcNoteCard,
		WerkplekAgentStatus,
		WerkplekInbox,
		WerkplekContactmomentPanel,
		WerkplekKennisSearch,
	},

	data() {
		return {
			loading: false,
			error: null,
			agentProfile: { userId: '', isAvailable: false, maxConcurrent: 0, skills: [] },
			assignedRequests: [],
			openTasks: [],
			queues: [],
			queueCounts: {},
			selectedQueue: '',
			activeContext: null,
		}
	},

	computed: {
		/**
		 * Whether at least one queue option exists; rendered as a v-if guard
		 * for the queue selector. Kept as a computed (rather than inline
		 * `queueOptions.length > 0`) so the template stays free of any `>`
		 * tokens inside attribute expressions — the nc-input-labels gate
		 * scans flattened-line regexes and would otherwise truncate the
		 * NcSelect opening tag before the input-label attribute is seen.
		 *
		 * @return {boolean}
		 */
		hasQueueOptions() {
			return this.queueOptions.length !== 0
		},
		/**
		 * Build the queue selector options with the open-counts in the label.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		queueOptions() {
			return this.queues.map(q => ({
				value: q.slug || q.id,
				label: q.title + ' (' + (this.queueCounts[q.slug] || 0) + ')',
			}))
		},
		/**
		 * Requests filtered by the queue selector (client-side; the server
		 * still scopes the list to the agent so the dataset is small).
		 *
		 * @return {Array<object>}
		 */
		filteredRequests() {
			if (!this.selectedQueue) return this.assignedRequests
			return this.assignedRequests.filter(r => {
				const ref = String(r.queue || '')
				return ref === this.selectedQueue
					|| ref === this.queueSlugById(this.selectedQueue)
					|| ref === this.queueIdBySlug(this.selectedQueue)
			})
		},
	},

	created() {
		this.fetchState()
	},

	methods: {
		/**
		 * Fetch the workspace state from the controller. Failures preserve
		 * the previous state and surface a user-facing NcNoteCard so the
		 * agent can retry by navigating away and back.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-4.1
		 */
		async fetchState() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/pipelinq/api/kcc-werkplek/state')
				const response = await axios.get(url)
				const data = response.data || {}
				this.agentProfile = data.agentProfile || this.agentProfile
				this.assignedRequests = Array.isArray(data.assignedRequests) ? data.assignedRequests : []
				this.openTasks = Array.isArray(data.openTasks) ? data.openTasks : []
				this.queues = Array.isArray(data.queues) ? data.queues : []
				this.queueCounts = data.queueCounts || {}
			} catch (e) {
				this.error = this.t('pipelinq', 'Failed to load workspace state. Please try again.')
				// eslint-disable-next-line no-console
				console.warn('[KccWerkplekPage] state fetch failed', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Update the local agent-profile state when the toggle child reports
		 * a server-confirmed availability change.
		 *
		 * @param {boolean} value New availability flag.
		 */
		onAvailabilityChange(value) {
			this.agentProfile = { ...this.agentProfile, isAvailable: Boolean(value) }
		},
		/**
		 * Forward an inbox selection to the contactmoment panel as context.
		 *
		 * @param {{kind: string, item: object}} payload Inbox selection.
		 */
		onSelectItem(payload) {
			this.activeContext = payload
		},
		/**
		 * The contactmoment panel finished saving; refresh the inbox so the
		 * agent sees the new state (a created task should appear in tasks).
		 *
		 * @param {object|null} saved The save payload (or null on clear).
		 */
		onPanelSaved(saved) {
			if (saved === null) {
				this.activeContext = null
				return
			}
			// Best-effort refresh — failures fall through silently.
			this.fetchState()
		},
		/**
		 * Resolve a queue slug to its UUID (for filter matching).
		 *
		 * @param {string} slug Queue slug.
		 *
		 * @return {string}
		 */
		queueIdBySlug(slug) {
			const match = this.queues.find(q => q.slug === slug)
			return match ? (match.id || '') : ''
		},
		/**
		 * Resolve a queue UUID to its slug (for filter matching).
		 *
		 * @param {string} id Queue UUID.
		 *
		 * @return {string}
		 */
		queueSlugById(id) {
			const match = this.queues.find(q => q.id === id)
			return match ? (match.slug || '') : ''
		},
	},
}
</script>

<style scoped>
.kcc-werkplek-page {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 600px;
	padding: 0;
}

.kcc-werkplek-page__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	/* Reserve room on the left for the Nextcloud app-navigation toggle so it
	   never overlaps the page title. */
	padding: 12px 16px 12px 52px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
}

.kcc-werkplek-page__header-left {
	display: flex;
	gap: 16px;
	align-items: center;
}

.kcc-werkplek-page__title { margin: 0; font-size: 1.3em; }

.kcc-werkplek-page__loading,
.kcc-werkplek-page__error {
	padding: 32px 16px;
	text-align: center;
}

.kcc-werkplek-page__layout {
	display: grid;
	grid-template-columns: minmax(280px, 320px) minmax(0, 1fr) minmax(260px, 300px);
	gap: 12px;
	padding: 12px;
	flex: 1;
	min-height: 0;
}

.kcc-werkplek-page__panel {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, var(--border-radius));
	overflow: hidden;
	display: flex;
	flex-direction: column;
	/* Allow the panel to shrink inside its grid track so wide children
	   (selects, the call timer) wrap instead of overflowing the tile. */
	min-width: 0;
}

.kcc-werkplek-page__panel--center { flex: 1; }

/* Stack to a single column before the center tile gets too narrow to hold
   the interaction form comfortably. */
@media (max-width: 900px) {
	.kcc-werkplek-page__layout {
		grid-template-columns: 1fr;
		grid-template-rows: auto auto auto;
	}
	.kcc-werkplek-page__panel--inbox,
	.kcc-werkplek-page__panel--kennis {
		max-height: 320px;
	}
}
</style>
