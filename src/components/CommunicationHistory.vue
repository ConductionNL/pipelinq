<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2024 Conduction B.V. -->
<!--
  - CommunicationHistory.vue
  -
  - Inline panel rendered inside the four entity detail views (client,
  - contact, lead, request). Fetches a paginated list of contactmoment
  - activity records from the entity-notes REST endpoint
  - `/api/activity/{entityType}/{entityId}?type=contactmomenten` and
  - renders them as a CnDataTable. Rows link to the contactmoment detail
  - route.
  -
  - @spec openspec/changes/entity-notes/tasks.md#task-5
  - @spec openspec/specs/entity-notes/spec.md
  -->

<template>
	<CnDetailCard :title="t('pipelinq', 'Communication History')">
		<template #header-actions>
			<NcButton
				:disabled="loading"
				:aria-label="t('pipelinq', 'Refresh')"
				@click="onRefresh">
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</template>

		<div v-if="loading && items.length === 0" class="communication-history__loading" role="status">
			<NcLoadingIcon :size="32" />
			<span class="communication-history__loading-text">
				{{ t('pipelinq', 'Loading communication history...') }}
			</span>
		</div>

		<div
			v-else-if="!loading && items.length === 0"
			class="communication-history__empty">
			<p>{{ t('pipelinq', 'No communication history yet') }}</p>
		</div>

		<template v-else>
			<CnDataTable
				:columns="columns"
				:rows="items"
				row-key="id"
				:row-class="rowClass"
				:loading="loading"
				:loading-text="t('pipelinq', 'Loading communication history...')"
				:empty-text="t('pipelinq', 'No communication history yet')"
				@row-click="goToContactmoment">
				<template #column-channel="{ value }">
					<span
						class="communication-history__channel"
						:aria-label="channelAriaLabel(value)">
						{{ formatChannel(value) }}
					</span>
				</template>
				<template #column-timestamp="{ value }">
					{{ formatTimestamp(value) }}
				</template>
			</CnDataTable>

			<CnPagination
				v-if="pages > 1"
				:current-page="page"
				:total-pages="pages"
				:total-items="total"
				:current-page-size="limit"
				@page-changed="onPageChanged" />
		</template>
	</CnDetailCard>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import {
	CnDetailCard,
	CnDataTable,
	CnPagination,
} from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

/**
 * Map the channel enum (telefoon|email|balie|chat|social|brief) onto the
 * English label used in screen-reader announcements. The visual label
 * stays Dutch because that's the canonical schema enum value.
 */
const CHANNEL_LABEL_KEYS = {
	telefoon: 'Phone',
	email: 'Email',
	balie: 'Counter',
	chat: 'Chat',
	social: 'Social media',
	brief: 'Letter',
}

export default {
	name: 'CommunicationHistory',

	components: {
		CnDetailCard,
		CnDataTable,
		CnPagination,
		NcButton,
		NcLoadingIcon,
	},

	props: {
		/**
		 * The entity type being viewed
		 * (`client|contact|lead|request`).
		 */
		entityType: {
			type: String,
			required: true,
			validator: (value) => ['client', 'contact', 'lead', 'request'].includes(value),
		},
		/**
		 * The OpenRegister UUID of the entity being viewed.
		 */
		entityId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			items: [],
			loading: false,
			page: 1,
			limit: 10,
			total: 0,
			pages: 1,
		}
	},

	computed: {
		columns() {
			return [
				{ key: 'channel', label: this.t('pipelinq', 'Channel') },
				{ key: 'subject', label: this.t('pipelinq', 'Subject') },
				{ key: 'agent', label: this.t('pipelinq', 'Agent') },
				{ key: 'timestamp', label: this.t('pipelinq', 'Date') },
			]
		},
	},

	watch: {
		entityId() {
			this.page = 1
			this.fetchHistory()
		},
		entityType() {
			this.page = 1
			this.fetchHistory()
		},
	},

	mounted() {
		this.fetchHistory()
	},

	methods: {
		/**
		 * Build the URL for the activity REST endpoint.
		 *
		 * @return {string} The fully-qualified backend URL.
		 */
		buildUrl() {
			return generateUrl(
				'/apps/pipelinq/api/activity/{entityType}/{entityId}',
				{
					entityType: this.entityType,
					entityId: this.entityId,
				},
			)
		},

		async fetchHistory() {
			if (!this.entityId) {
				return
			}

			this.loading = true
			try {
				const response = await axios.get(this.buildUrl(), {
					params: {
						type: 'contactmomenten',
						_page: this.page,
						_limit: this.limit,
					},
				})
				const data = response.data || {}
				this.items = Array.isArray(data.results) ? data.results : []
				this.total = Number(data.total) || 0
				this.pages = Number(data.pages) || 1
				this.page = Number(data.page) || this.page
			} catch (error) {
				showError(this.t('pipelinq', 'Could not load communication history'))
				this.items = []
				this.total = 0
				this.pages = 1
			} finally {
				this.loading = false
			}
		},

		onRefresh() {
			this.page = 1
			this.fetchHistory()
		},

		onPageChanged(newPage) {
			const next = Number(newPage)
			if (!Number.isFinite(next) || next < 1) {
				return
			}
			this.page = next
			this.fetchHistory()
		},

		/**
		 * Open a communication-history row on the unified ticket detail page.
		 *
		 * @param {object} row The contactmoment row (a `ticket` object).
		 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
		 */
		goToContactmoment(row) {
			if (!row || !row.id) {
				return
			}
			// A contactmoment is a `ticket` with ticketType=contactmoment
			// (unify-ticket-supertype) — open the unified detail page.
			this.$router.push({
				name: 'TicketDetail',
				params: { id: row.id },
			})
		},

		formatChannel(value) {
			if (!value) {
				return '-'
			}
			return value
		},

		channelAriaLabel(value) {
			const key = CHANNEL_LABEL_KEYS[String(value || '').toLowerCase()]
			if (!key) {
				return this.t('pipelinq', 'Channel')
			}
			return this.t('pipelinq', key)
		},

		formatTimestamp(value) {
			if (!value) {
				return '-'
			}
			const parsed = new Date(value)
			if (Number.isNaN(parsed.getTime())) {
				return value
			}
			return parsed.toLocaleString()
		},

		rowClass() {
			return 'communication-history__row'
		},
	},
}
</script>

<style scoped>
.communication-history__loading {
	display: flex;
	align-items: center;
	gap: var(--cn-spacing-2, 8px);
	padding: var(--cn-spacing-3, 12px) 0;
	color: var(--color-text-maxcontrast);
}

.communication-history__loading-text {
	font-size: var(--default-font-size, 14px);
}

.communication-history__empty {
	padding: var(--cn-spacing-4, 16px) 0;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.communication-history__channel {
	display: inline-block;
	padding: var(--cn-spacing-1, 2px) var(--cn-spacing-2, 8px);
	border-radius: var(--border-radius-pill, 12px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: var(--default-font-size, 14px);
}

/* These used the Vue-2 spelling of the deep selector (`::v-deep(…)`). Vue 3's
   SFC compiler expects the `:deep(…)` form below; stylelint rejected the old
   one as an unknown pseudo-element (`selector-pseudo-element-no-unknown`),
   which is what surfaced it. */
:deep(.communication-history__row) {
	cursor: pointer;
}

:deep(.communication-history__row:hover) {
	background-color: var(--color-background-hover);
}
</style>
