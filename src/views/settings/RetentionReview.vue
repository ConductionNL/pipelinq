<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="retention-review">
		<h2 class="retention-review__title">
			{{ t('pipelinq', 'Retention review') }}
		</h2>
		<p class="retention-review__intro">
			{{ t('pipelinq', 'Clients and contact persons whose retention term has passed. Nothing is destroyed until you approve a list.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<p v-else-if="error" class="retention-review__error" role="alert">
			{{ error }}
		</p>

		<NcEmptyContent
			v-else-if="!configured"
			:name="t('pipelinq', 'Destruction lists are not set up')"
			:description="t('pipelinq', 'OpenRegister has no register for destruction lists yet. Ask an administrator to set it up.')" />

		<NcEmptyContent
			v-else-if="lists.length === 0"
			:name="t('pipelinq', 'Nothing to review')"
			:description="t('pipelinq', 'No destruction list holds clients or contact persons.')" />

		<section
			v-for="list in lists"
			v-else
			:key="list.uuid"
			class="retention-review__list"
			:data-list="list.uuid">
			<header class="retention-review__list-header">
				<h3>{{ t('pipelinq', 'Destruction list of {date}', { date: formatDate(list.createdAt) }) }}</h3>
				<span class="retention-review__status">{{ statusLabel(list.status) }}</span>
			</header>

			<table class="retention-review__table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Name') }}</th>
						<th>{{ t('pipelinq', 'Type') }}</th>
						<th>{{ t('pipelinq', 'Destruction date') }}</th>
						<th>{{ t('pipelinq', 'Tickets') }}</th>
						<th>{{ t('pipelinq', 'Leads') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in list.entries" :key="entry.uuid" :data-entry="entry.uuid">
						<td>{{ entry.title || entry.uuid }}</td>
						<td>{{ entry.kind === 'client' ? t('pipelinq', 'Client') : t('pipelinq', 'Contact person') }}</td>
						<td>{{ formatDate(entry.archiefactiedatum) }}</td>
						<td>{{ entry.kind === 'client' ? entry.tickets : '' }}</td>
						<td>{{ entry.kind === 'client' ? entry.leads : '' }}</td>
					</tr>
				</tbody>
			</table>

			<div v-if="isOpen(list)" class="retention-review__actions">
				<NcButton variant="primary" @click="decide(list, 'approve')">
					{{ t('pipelinq', 'Approve') }}
				</NcButton>
				<NcButton variant="secondary" @click="decide(list, 'reject')">
					{{ t('pipelinq', 'Reject') }}
				</NcButton>
			</div>
		</section>

		<DestructionListDecisionModal
			v-if="pending"
			:decision="pending.decision"
			:entryCount="pending.list.entries.length"
			:busy="saving"
			@close="pending = null"
			@confirm="submit" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import DestructionListDecisionModal from '../../modals/DestructionListDecisionModal.vue'
import { jsonRequestHeaders } from '../../services/requestHeaders.js'
import { initializeStores } from '../../store/store.js'

const LISTS_PATH = '/apps/openregister/api/archival/destruction-lists'

/** Statuses a records officer can still approve or reject. */
const OPEN_STATUSES = ['in_review', 'awaiting_second_approval']

/**
 * Read JSON from an OpenRegister path; a refused request throws its status.
 *
 * @param {string} path The path below the Nextcloud root.
 * @param {object} [init] The fetch options.
 * @return {Promise<object>} The answer.
 */
async function requestJson(path, init = {}) {
	const response = await fetch(generateUrl(path), { ...init, headers: jsonRequestHeaders() })
	const data = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw new Error(data.error || data.message || response.statusText || String(response.status))
	}

	return data
}

/**
 * How many objects of a schema point at a client; a failed count reads as 0.
 *
 * @param {string} schema The pipelinq schema slug (ticket or lead).
 * @param {string} clientId The client's uuid.
 * @return {Promise<number>} The total.
 */
async function countReferences(schema, clientId) {
	try {
		const data = await requestJson(`/apps/openregister/api/objects/pipelinq/${schema}?client=${encodeURIComponent(clientId)}&_limit=1`)
		return Number(data.total) || 0
	} catch {
		return 0
	}
}

/**
 * Lists the destruction lists that hold clients or contact persons, with the
 * tickets and leads that still point at each client, and lets a records
 * officer approve or reject a list. It writes nothing itself: approve and
 * reject are OpenRegister's archival routes.
 *
 * @spec openspec/changes/platform-client-retention/specs/client-retention/spec.md
 */
export default {
	name: 'RetentionReview',
	components: { DestructionListDecisionModal, NcButton, NcEmptyContent, NcLoadingIcon },

	data() {
		return {
			loading: true,
			error: '',
			configured: true,
			lists: [],
			pending: null,
			saving: false,
		}
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * The schema ids and slugs that count as client or contact person.
		 *
		 * @return {Promise<{client: string[], contact: string[]}>} The identifiers per kind.
		 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
		 */
		async schemaKinds() {
			let config = {}
			try {
				const { settingsStore } = await initializeStores()
				config = settingsStore.config || {}
			} catch {
				// Settings unreadable: match on slug only.
			}

			const ids = (key, slug) => [slug, config[key]].filter((v) => v !== undefined && v !== null && v !== '').map(String)
			return { client: ids('client_schema', 'client'), contact: ids('contact_schema', 'contact') }
		},

		/**
		 * Load every destruction list and keep the client and contact entries.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const summary = await requestJson(LISTS_PATH)
				this.configured = summary.configured !== false
				const kinds = await this.schemaKinds()
				const lists = []
				for (const item of (summary.results || [])) {
					const detail = await requestJson(`${LISTS_PATH}/${encodeURIComponent(item.uuid)}`)
					const entries = this.relevantEntries(detail.objects || [], kinds)
					if (entries.length === 0) {
						continue
					}
					await this.countLinks(entries)
					lists.push({ uuid: item.uuid, status: detail.status || item.status, createdAt: item.createdAt, entries })
				}
				this.lists = lists
			} catch (e) {
				this.error = t('pipelinq', 'The destruction lists could not be loaded: {reason}', { reason: e.message })
			} finally {
				this.loading = false
			}
		},

		/**
		 * The entries about a client or a contact person, each tagged with its kind.
		 *
		 * @param {Array<object>} entries The list's entries.
		 * @param {{client: string[], contact: string[]}} kinds The schema identifiers per kind.
		 * @return {Array<object>} The relevant entries.
		 */
		relevantEntries(entries, kinds) {
			return entries
				.map((entry) => {
					const schema = String(entry.schema ?? '')
					const kind = ['client', 'contact'].find((k) => kinds[k].includes(schema))
					return kind ? { ...entry, kind, tickets: 0, leads: 0 } : null
				})
				.filter(Boolean)
		},

		/**
		 * Count the tickets and leads that reference each client entry.
		 *
		 * @param {Array<object>} entries The relevant entries, updated in place.
		 * @return {Promise<void>}
		 */
		async countLinks(entries) {
			for (const entry of entries) {
				if (entry.kind !== 'client') {
					continue
				}
				entry.tickets = await countReferences('ticket', entry.uuid)
				entry.leads = await countReferences('lead', entry.uuid)
			}
		},

		/**
		 * Whether a list still waits for a decision.
		 *
		 * @param {object} list The list.
		 * @return {boolean} True while it can be approved or rejected.
		 */
		isOpen(list) {
			return OPEN_STATUSES.includes(list.status)
		},

		/**
		 * A list's status in words.
		 *
		 * @param {string} status The OpenRegister status.
		 * @return {string} The label.
		 */
		statusLabel(status) {
			const labels = {
				in_review: t('pipelinq', 'In review'),
				awaiting_second_approval: t('pipelinq', 'Awaiting a second approval'),
				approved: t('pipelinq', 'Approved'),
				rejected: t('pipelinq', 'Rejected'),
				completed: t('pipelinq', 'Completed'),
			}
			return labels[status] || status || ''
		},

		/**
		 * A date as the user's locale writes it, or a dash.
		 *
		 * @param {string|null} value An ISO date.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			if (!value) {
				return '-'
			}
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleDateString()
		},

		/**
		 * Ask for confirmation before approving or rejecting a list.
		 *
		 * @param {object} list The list.
		 * @param {'approve'|'reject'} decision The decision.
		 * @return {void}
		 */
		decide(list, decision) {
			this.pending = { list, decision }
		},

		/**
		 * Send the confirmed decision to OpenRegister and reload.
		 *
		 * @param {{reason?: string}} payload The reason, for a rejection.
		 * @return {Promise<void>}
		 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
		 */
		async submit(payload = {}) {
			const { list, decision } = this.pending
			const body = decision === 'approve' ? { action: 'approve_all' } : { reason: payload.reason }
			this.saving = true
			try {
				await requestJson(`${LISTS_PATH}/${encodeURIComponent(list.uuid)}/${decision}`, {
					method: 'POST',
					body: JSON.stringify(body),
				})
				showSuccess(decision === 'approve' ? t('pipelinq', 'Destruction list approved') : t('pipelinq', 'Destruction list rejected'))
				this.pending = null
				await this.load()
			} catch (e) {
				showError(t('pipelinq', 'The decision could not be saved: {reason}', { reason: e.message }))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.retention-review {
	padding: 16px 24px;
	max-width: 1100px;
}

.retention-review__intro {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.retention-review__error {
	color: var(--color-error-text);
}

.retention-review__list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	margin-bottom: 16px;
}

.retention-review__list-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.retention-review__status {
	color: var(--color-text-maxcontrast);
}

.retention-review__table {
	width: 100%;
	border-collapse: collapse;
	margin: 8px 0;
}

.retention-review__table th,
.retention-review__table td {
	text-align: start;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.retention-review__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
</style>
