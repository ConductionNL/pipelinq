<template>
	<div v-if="editing || isNew">
		<div class="contactmoment-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New contactmoment') }}
			</h2>
			<h2 v-else>
				{{ contactmomentData.subject || t('pipelinq', 'Contactmoment') }}
			</h2>
		</div>
		<ContactmomentQuickLog
			:client-id="isNew ? null : contactmomentData.client"
			:request-id="isNew ? null : contactmomentData.request"
			@saved="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="contactmomentData.subject || t('pipelinq', 'Contactmoment')"
		:subtitle="t('pipelinq', 'Contactmoment')"
		:back-route="{ name: 'Contactmomenten' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_contactmoment"
		:object-id="contactmomentId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton
				v-if="canDelete"
				type="error"
				@click="showDeleteDialog = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Contact Information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Channel') }}</label>
					<span class="channel-display">
						<component :is="getChannelIcon(contactmomentData.channel)" :size="16" />
						{{ getChannelLabel(contactmomentData.channel) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Outcome') }}</label>
					<span v-if="contactmomentData.outcome" class="outcome-badge" :class="'outcome-' + contactmomentData.outcome">
						{{ getOutcomeLabel(contactmomentData.outcome) }}
					</span>
					<span v-else>-</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Agent') }}</label>
					<span>{{ contactmomentData.agent || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Contacted at') }}</label>
					<span>{{ formatDate(contactmomentData.contactedAt) }}</span>
				</div>
				<div v-if="contactmomentData.duration" class="info-field">
					<label>{{ t('pipelinq', 'Duration') }}</label>
					<span>{{ contactmomentData.duration }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard v-if="contactmomentData.summary" :title="t('pipelinq', 'Summary')">
			<p>{{ contactmomentData.summary }}</p>
		</CnDetailCard>

		<CnDetailCard v-if="contactmomentData.notes" :title="t('pipelinq', 'Notes')">
			<p>{{ contactmomentData.notes }}</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Client')">
			<div v-if="clientData" class="client-link">
				<a href="#" @click.prevent="$router.push({ name: 'ClientDetail', params: { id: clientData.id } })">
					{{ clientData.name }}
				</a>
				<span v-if="clientData.email" class="client-meta">{{ clientData.email }}</span>
			</div>
			<p v-else-if="contactmomentData.client" class="section-empty orphaned-ref">
				{{ t('pipelinq', '[Deleted client]') }}
			</p>
			<p v-else class="section-empty">
				{{ t('pipelinq', 'No client linked') }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Request')">
			<div v-if="requestData" class="request-link">
				<a href="#" @click.prevent="$router.push({ name: 'RequestDetail', params: { id: requestData.id } })">
					{{ requestData.title }}
				</a>
			</div>
			<p v-else-if="contactmomentData.request" class="section-empty orphaned-ref">
				{{ t('pipelinq', '[Deleted request]') }}
			</p>
			<p v-else class="section-empty">
				{{ t('pipelinq', 'No request linked') }}
			</p>
		</CnDetailCard>

		<CnDetailCard
			v-if="contactmomentData.channelMetadata && Object.keys(contactmomentData.channelMetadata).length > 0"
			:title="t('pipelinq', 'Channel Metadata')">
			<div class="metadata-grid">
				<div v-for="(value, key) in contactmomentData.channelMetadata" :key="key" class="info-field">
					<label>{{ key }}</label>
					<span>{{ value }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- Delete dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Delete contactmoment')"
			@closing="showDeleteDialog = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete this contactmoment?') }}</p>
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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ContactmomentQuickLog from '../../components/ContactmomentQuickLog.vue'
import { useObjectStore } from '../../store/modules/object.js'
import Phone from 'vue-material-design-icons/Phone.vue'
import Email from 'vue-material-design-icons/Email.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'

export default {
	name: 'ContactmomentDetail',
	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
		ContactmomentQuickLog,
		Phone,
		Email,
		AccountGroup,
		Chat,
		ShareVariant,
		EmailOutline,
	},
	props: {
		contactmomentId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			showDeleteDialog: false,
			deleting: false,
			clientData: null,
			requestData: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.contactmomentId || this.contactmomentId === 'new'
		},
		loading() {
			return this.objectStore.loading.contactmoment || false
		},
		contactmomentData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('contactmoment', this.contactmomentId) || {}
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.contactmoment || {}
			return {
				title: t('pipelinq', 'Contactmoment'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
		canDelete() {
			const currentUser = OC.currentUser
			return this.contactmomentData.agent === currentUser || OC.isAdmin
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('contactmoment', this.contactmomentId)
			await this.fetchRelated()
		}
	},
	methods: {
		async fetchRelated() {
			if (this.contactmomentData.client) {
				const client = await this.objectStore.fetchObject('client', this.contactmomentData.client)
				this.clientData = client || null
			}
			if (this.contactmomentData.request) {
				const request = await this.objectStore.fetchObject('request', this.contactmomentData.request)
				this.requestData = request || null
			}
		},

		getChannelIcon(channel) {
			const icons = {
				telefoon: 'Phone',
				email: 'Email',
				balie: 'AccountGroup',
				chat: 'Chat',
				social: 'ShareVariant',
				brief: 'EmailOutline',
			}
			return icons[channel] || 'Phone'
		},

		getChannelLabel(channel) {
			const labels = {
				telefoon: t('pipelinq', 'Telefoon'),
				email: t('pipelinq', 'E-mail'),
				balie: t('pipelinq', 'Balie'),
				chat: t('pipelinq', 'Chat'),
				social: t('pipelinq', 'Social media'),
				brief: t('pipelinq', 'Brief'),
			}
			return labels[channel] || channel || '-'
		},

		getOutcomeLabel(outcome) {
			const labels = {
				afgehandeld: t('pipelinq', 'Afgehandeld'),
				doorverbonden: t('pipelinq', 'Doorverbonden'),
				terugbelverzoek: t('pipelinq', 'Terugbelverzoek'),
				vervolgactie: t('pipelinq', 'Vervolgactie'),
			}
			return labels[outcome] || outcome || '-'
		},

		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleString()
			} catch {
				return dateStr
			}
		},

		async onFormSave() {
			if (this.isNew) {
				this.$router.push({ name: 'Contactmomenten' })
			} else {
				await this.objectStore.fetchObject('contactmoment', this.contactmomentId)
				await this.fetchRelated()
				this.editing = false
			}
		},

		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Contactmomenten' })
			} else {
				this.editing = false
			}
		},

		async confirmDelete() {
			this.deleting = true
			try {
				await axios.delete(
					generateUrl('/apps/pipelinq/api/contactmomenten/{id}', { id: this.contactmomentId }),
				)
				showSuccess(t('pipelinq', 'Contactmoment deleted'))
				this.$router.push({ name: 'Contactmomenten' })
			} catch (error) {
				const status = error?.response?.status
				if (status === 403) {
					showError(t('pipelinq', 'You do not have permission to delete this contactmoment'))
				} else if (status === 404) {
					showError(t('pipelinq', 'Contactmoment not found'))
				} else {
					showError(error?.response?.data?.error || t('pipelinq', 'Failed to delete contactmoment'))
				}
			} finally {
				this.deleting = false
				this.showDeleteDialog = false
			}
		},
	},
}
</script>

<style scoped>
.contactmoment-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.metadata-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.info-field span,
.info-field p {
	margin: 0;
}

.channel-display {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.outcome-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.outcome-afgehandeld {
	background: var(--color-success);
	color: white;
}

.outcome-doorverbonden {
	background: #2196f3;
	color: white;
}

.outcome-terugbelverzoek {
	background: #ff9800;
	color: white;
}

.outcome-vervolgactie {
	background: #9c27b0;
	color: white;
}

.client-link a,
.request-link a {
	font-weight: bold;
	color: var(--color-primary);
}

.client-meta {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.section-empty {
	color: var(--color-text-maxcontrast);
}

.orphaned-ref {
	font-style: italic;
	color: var(--color-warning);
}
</style>
