<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - CommunicationHistory.vue
  -
  - Inline panel showing linked contactmomenten for an entity in reverse
  - chronological order. Consumes the per-entity activity REST API
  - (GET /api/activity/{entityType}/{entityId}?type=contactmomenten). Embedded
  - in the client, contact, lead and request detail views.
  -
  - @spec openspec/changes/entity-notes/tasks.md#task-5
  -->

<template>
	<CnDetailCard :title="t('pipelinq', 'Communication History')">
		<template #header-actions>
			<NcButton type="tertiary" :aria-label="t('pipelinq', 'Refresh')" @click="reload">
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</template>

		<NcLoadingIcon v-if="loading && items.length === 0" :size="32" />

		<NcEmptyContent
			v-else-if="items.length === 0 && !loading"
			:name="t('pipelinq', 'No communication history yet')">
			<template #icon>
				<MessageTextOutline :size="48" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<CnDataTable
				:columns="columns"
				:rows="items"
				row-key="id"
				:empty-text="t('pipelinq', 'No communication history yet')"
				@row-click="goToContactmoment">
				<template #column-channel="{ row }">
					<span class="communication-history__channel">
						<component
							:is="iconForChannel(row.channel)"
							:size="20"
							:aria-label="channelLabel(row.channel)"
							role="img" />
						<span class="communication-history__channel-label">{{ channelLabel(row.channel) }}</span>
					</span>
				</template>
				<template #column-timestamp="{ row }">
					{{ formatDate(row.timestamp) }}
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
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { CnDetailCard, CnDataTable, CnPagination } from '@conduction/nextcloud-vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import Email from 'vue-material-design-icons/Email.vue'
import AccountVoice from 'vue-material-design-icons/AccountVoice.vue'
import Message from 'vue-material-design-icons/Message.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import EmailNewsletter from 'vue-material-design-icons/EmailNewsletter.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'

export default {
	name: 'CommunicationHistory',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		CnDetailCard,
		CnDataTable,
		CnPagination,
		Phone,
		Email,
		AccountVoice,
		Message,
		AccountGroup,
		EmailNewsletter,
		MessageTextOutline,
	},
	props: {
		entityType: {
			type: String,
			required: true,
		},
		entityId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			items: [],
			page: 1,
			pages: 1,
			total: 0,
			limit: 10,
			loading: false,
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
		entityId: 'reload',
		entityType: 'reload',
	},
	mounted() {
		this.fetchHistory()
	},
	methods: {
		/**
		 * Reset pagination and reload from the first page.
		 *
		 * @return {void}
		 */
		reload() {
			this.page = 1
			this.fetchHistory()
		},
		/**
		 * Fetch the current page of contactmomenten from the activity API.
		 *
		 * @return {Promise<void>}
		 */
		async fetchHistory() {
			if (!this.entityId || !this.entityType) {
				return
			}
			this.loading = true
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/activity/{entityType}/{entityId}',
					{ entityType: this.entityType, entityId: this.entityId },
				)
				const { data } = await axios.get(url, {
					params: {
						type: 'contactmomenten',
						_page: this.page,
						_limit: this.limit,
					},
				})
				this.items = Array.isArray(data.results) ? data.results : []
				this.total = data.total || 0
				this.pages = data.pages || 1
				this.page = data.page || this.page
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to load communication history'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Handle a page change from the pagination control.
		 *
		 * @param {number} newPage The new 1-based page number.
		 *
		 * @return {void}
		 */
		onPageChanged(newPage) {
			this.page = newPage
			this.fetchHistory()
		},
		/**
		 * Navigate to the full contactmoment detail page.
		 *
		 * @param {object} row The clicked activity row.
		 *
		 * @return {void}
		 */
		goToContactmoment(row) {
			if (!row || !row.id) {
				return
			}
			this.$router.push({ name: 'ContactmomentDetail', params: { id: row.id } })
		},
		/**
		 * Resolve the icon component for a communication channel.
		 *
		 * @param {string} channel The contactmoment channel.
		 *
		 * @return {object} The icon component.
		 */
		iconForChannel(channel) {
			switch (channel) {
			case 'telefoon': return Phone
			case 'email': return Email
			case 'balie': return AccountVoice
			case 'chat': return Message
			case 'social': return AccountGroup
			case 'brief': return EmailNewsletter
			default: return Message
			}
		},
		/**
		 * Human-readable label for a communication channel.
		 *
		 * @param {string} channel The contactmoment channel.
		 *
		 * @return {string} The translated channel label.
		 */
		channelLabel(channel) {
			switch (channel) {
			case 'telefoon': return this.t('pipelinq', 'Phone')
			case 'email': return this.t('pipelinq', 'Email')
			case 'balie': return this.t('pipelinq', 'Counter')
			case 'chat': return this.t('pipelinq', 'Chat')
			case 'social': return this.t('pipelinq', 'Social media')
			case 'brief': return this.t('pipelinq', 'Letter')
			default: return channel || this.t('pipelinq', 'Unknown')
			}
		},
		/**
		 * Format an ISO timestamp for display in the Dutch locale.
		 *
		 * @param {string} dateStr The ISO 8601 timestamp.
		 *
		 * @return {string} The formatted date, or an empty string.
		 */
		formatDate(dateStr) {
			if (!dateStr) {
				return ''
			}
			const date = new Date(dateStr)
			if (isNaN(date.getTime())) {
				return ''
			}
			return date.toLocaleString('nl-NL', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.communication-history__channel {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.communication-history__channel-label {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}
</style>
