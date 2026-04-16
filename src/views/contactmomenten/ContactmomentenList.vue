<template>
	<div class="contactmomenten-container">
		<!-- Header with title, search, and export -->
		<div class="contactmomenten-header">
			<div class="header-left">
				<h2>{{ t('pipelinq', 'Contact Moments') }}</h2>
				<input
					v-model="searchQuery"
					type="text"
					class="search-input"
					:placeholder="t('pipelinq', 'Search by subject')"
					@keyup="onSearch" />
			</div>
			<div class="header-actions">
				<NcButton
					type="secondary"
					@click="exportCSV">
					{{ t('pipelinq', 'Export CSV') }}
				</NcButton>
				<NcButton
					type="primary"
					@click="showQuickLog = true">
					{{ t('pipelinq', 'New contact moment') }}
				</NcButton>
			</div>
		</div>

		<!-- Content with reduced title display -->
		<CnIndexPage
			:title="t('pipelinq', '')"
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
			:name="t('pipelinq', 'New contact moment')"
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
import { useObjectStore } from '../../store/modules/object.js'
import ContactmomentQuickLog from '../../components/ContactmomentQuickLog.vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import Email from 'vue-material-design-icons/Email.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import Chat from 'vue-material-design-icons/Chat.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import Download from 'vue-material-design-icons/Download.vue'

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
		Download,
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
			searchQuery: '',
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

		exportCSV() {
			if (!this.objects || this.objects.length === 0) {
				alert(t('pipelinq', 'No contact moments to export'))
				return
			}

			const headers = ['Subject', 'Channel', 'Agent', 'Date', 'Outcome']
			const rows = this.objects.map(obj => [
				obj.subject || '-',
				this.getChannelLabel(obj.channel) || '-',
				obj.agent || '-',
				this.formatDate(obj.contactedAt) || '-',
				this.getOutcomeLabel(obj.outcome) || '-',
			])

			const csv = [
				headers.join(','),
				...rows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(',')),
			].join('\n')

			const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
			const link = document.createElement('a')
			const url = URL.createObjectURL(blob)
			link.setAttribute('href', url)
			link.setAttribute('download', `contactmomenten-${new Date().toISOString().split('T')[0]}.csv`)
			link.style.visibility = 'hidden'
			document.body.appendChild(link)
			link.click()
			document.body.removeChild(link)
		},

		onSearch() {
			// Search is handled by CnIndexPage search functionality
			// This method can be extended for custom search logic if needed
		},
	},
}
</script>

<style scoped>
.contactmomenten-container {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.contactmomenten-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
	background: var(--color-main-background);
	gap: 16px;
}

.header-left {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.header-left h2 {
	margin: 0;
	padding: 0;
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
}

.header-actions {
	display: flex;
	gap: 8px;
	white-space: nowrap;
}

.search-input {
	width: 100%;
	max-width: 300px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.search-input::placeholder {
	color: var(--color-text-maxcontrast);
}

.search-input:focus {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.1);
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
