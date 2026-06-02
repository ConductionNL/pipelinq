<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<CnDetailPage
		:title="contactmoment.subject || t('pipelinq', 'Contact moment')"
		:subtitle="t('pipelinq', 'Contact moment')"
		:back-route="{ name: 'Contactmomenten' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading">
		<template #actions>
			<NcButton
				type="error"
				:disabled="deleting"
				@click="showDeleteDialog = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Contact moment details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Subject') }}</label>
					<span>{{ contactmoment.subject || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Channel') }}</label>
					<span>{{ contactmoment.channel || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Outcome') }}</label>
					<span>{{ contactmoment.outcome || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Agent') }}</label>
					<span>{{ contactmoment.agent || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Contacted at') }}</label>
					<span>{{ formatDate(contactmoment.contactedAt) }}</span>
				</div>
				<div v-if="contactmoment.duration" class="info-field">
					<label>{{ t('pipelinq', 'Duration') }}</label>
					<span>{{ contactmoment.duration }}</span>
				</div>
			</div>
			<div v-if="contactmoment.summary" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Summary') }}</label>
				<p>{{ contactmoment.summary }}</p>
			</div>
			<div v-if="contactmoment.notes" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Notes') }}</label>
				<p>{{ contactmoment.notes }}</p>
			</div>
		</CnDetailCard>

		<!-- Delete confirmation dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Delete contact moment')"
			@closing="showDeleteDialog = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete this contact moment? This action cannot be undone.') }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="deleting" @click="confirmDelete">
					{{ deleting ? t('pipelinq', 'Deleting...') : t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailPage>
</template>

<script>
// SPDX-License-Identifier: EUPL-1.2
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcDialog, CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactmomentDetailView',
	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
	},
	props: {
		id: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			deleting: false,
			showDeleteDialog: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		contactmomentId() {
			return this.id || this.$route.params.id || null
		},
		contactmoment() {
			if (!this.contactmomentId) return {}
			return this.objectStore.getObject('contactmoment', this.contactmomentId) || {}
		},
	},
	async mounted() {
		if (this.contactmomentId) {
			this.loading = true
			try {
				await this.objectStore.fetchObject('contactmoment', this.contactmomentId)
			} catch (error) {
				showError(t('pipelinq', 'Failed to load contact moment'))
			} finally {
				this.loading = false
			}
		}
	},
	methods: {
		/**
		 * Format an ISO date string for display.
		 *
		 * @param {string} dateString The ISO date string.
		 * @return {string} The formatted date.
		 */
		formatDate(dateString) {
			if (!dateString) return '-'
			try {
				return new Date(dateString).toLocaleString()
			} catch {
				return dateString
			}
		},
		/**
		 * Delete the contactmoment via the permission-checked backend API.
		 * Only the creating agent or an admin may delete.
		 *
		 * @spec openspec/changes/contactmomenten/tasks.md#task-2.3
		 */
		async confirmDelete() {
			this.deleting = true
			this.showDeleteDialog = false
			try {
				await axios.delete(generateUrl('/apps/pipelinq/api/contactmomenten/' + this.contactmomentId))
				this.$router.push({ name: 'Contactmomenten' })
			} catch (error) {
				const status = error.response?.status
				if (status === 403) {
					showError(t('pipelinq', 'You do not have permission to delete this contact moment'))
				} else if (status === 404) {
					showError(t('pipelinq', 'Contact moment not found'))
				} else {
					showError(t('pipelinq', 'Failed to delete contact moment'))
				}
			} finally {
				this.deleting = false
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.info-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.info-field--full {
	grid-column: 1 / -1;
}

.info-field label {
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.info-field span,
.info-field p {
	color: var(--color-text-light);
	margin: 0;
}
</style>
