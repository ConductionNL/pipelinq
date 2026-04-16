<template>
	<div>
		<div class="contactmomenten-header">
			<h2>{{ t('pipelinq', 'Contact Moments') }}</h2>
			<NcButton type="secondary" @click="exportCsv">
				{{ t('pipelinq', 'Export CSV') }}
			</NcButton>
		</div>

		<CnIndexPage
			:title="t('pipelinq', 'Contactmomenten')"
			:description="t('pipelinq', 'Registered contact moments')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			@add="showQuickLog = true"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openContactmoment"
			@page-changed="onPageChange">
			<template #column-channel="{ value }">
				<span class="channel-badge">
					<component :is="getChannelIcon(value)" :size="16" />
					{{ getChannelLabel(value) }}
				</span>
			</template>

			<template #column-outcome="{ value }">
				<span v-if="value" class="outcome-badge" :class="'outcome-' + value">
					{{ getOutcomeLabel(value) }}
				</span>
				<span v-else class="outcome-empty">-</span>
			</template>

			<template #column-contactedAt="{ value }">
				{{ formatDate(value) }}
			</template>
		</CnIndexPage>

		<!-- Quick-log dialog -->
		<NcDialog
			v-if="showQuickLog"
			:name="t('pipelinq', 'New contactmoment')"
			size="normal"
			@closing="showQuickLog = false">
			<ContactmomentQuickLog
				:inline="true"
				@saved="onQuickLogSaved"
				@cancel="showQuickLog = false" />
		</NcDialog>
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcDialog, NcButton } from '@nextcloud/vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import ContactmomentQuickLog from '../../components/ContactmomentQuickLog.vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import Email from 'vue-material-design-icons/Email.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'

export default {
	name: 'ContactmomentenList',
	components: {
		CnIndexPage,
		NcDialog,
		NcButton,
		ContactmomentQuickLog,
		Phone,
		Email,
		AccountGroup,
		Chat,
		ShareVariant,
		EmailOutline,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('contactmoment', {
			sidebarState,
			objectStore,
			defaultSort: { key: 'contactedAt', order: 'desc' },
		})
	},

	data() {
		return {
			showQuickLog: false,
		}
	},

	methods: {
		openContactmoment(row) {
			this.$router.push({ name: 'ContactmomentDetail', params: { id: row.id } })
		},

		onQuickLogSaved() {
			this.showQuickLog = false
			this.refresh()
		},

		exportCsv() {
			const url = generateUrl('/apps/pipelinq/api/contactmomenten/export')
			window.open(url, '_blank')
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
	},
}
</script>

<style scoped>
.contactmomenten-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.contactmomenten-header h2 {
	margin: 0;
}

.channel-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
}

.outcome-badge {
	display: inline-block;
	padding: 2px 8px;
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

.outcome-empty {
	color: var(--color-text-maxcontrast);
}
</style>
