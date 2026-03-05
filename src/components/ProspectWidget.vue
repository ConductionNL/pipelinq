<template>
	<div class="prospect-widget">
		<div class="prospect-widget__header" @click="expanded = !expanded">
			<div class="prospect-widget__title">
				<h3>{{ t('pipelinq', 'Prospect Discovery') }}</h3>
				<span v-if="prospectStore.total > 0" class="prospect-count">
					{{ prospectStore.total }} {{ t('pipelinq', 'found') }}
				</span>
			</div>
			<div class="prospect-widget__actions">
				<NcButton
					v-if="expanded"
					type="tertiary"
					:disabled="prospectStore.loading"
					:aria-label="t('pipelinq', 'Refresh prospects')"
					@click.stop="refresh">
					<template #icon>
						<Refresh :size="20" :class="{ 'icon-spinning': prospectStore.loading }" />
					</template>
				</NcButton>
				<span class="expand-icon">{{ expanded ? '\u25B2' : '\u25BC' }}</span>
			</div>
		</div>

		<!-- Collapsed preview -->
		<div v-if="!expanded && prospectStore.prospects.length > 0" class="prospect-widget__preview">
			{{ t('pipelinq', 'Top match: {name}', { name: prospectStore.prospects[0].tradeName }) }}
		</div>

		<!-- Expanded content -->
		<div v-if="expanded" class="prospect-widget__body">
			<NcLoadingIcon v-if="prospectStore.loading" />

			<!-- No ICP configured -->
			<div v-else-if="prospectStore.error && prospectStore.error.includes('ICP')" class="prospect-widget__setup">
				<p>{{ t('pipelinq', 'Configure your Ideal Customer Profile in admin settings to discover prospects.') }}</p>
			</div>

			<!-- Error -->
			<div v-else-if="prospectStore.error" class="prospect-widget__error">
				<p>{{ prospectStore.error }}</p>
				<NcButton @click="refresh">
					{{ t('pipelinq', 'Retry') }}
				</NcButton>
			</div>

			<!-- No results -->
			<div v-else-if="prospectStore.prospects.length === 0" class="prospect-widget__empty">
				<p>{{ t('pipelinq', 'No prospects found matching your profile.') }}</p>
			</div>

			<!-- Prospect list -->
			<div v-else class="prospect-widget__list">
				<ProspectCard
					v-for="prospect in prospectStore.prospects"
					:key="prospect.kvkNumber"
					:prospect="prospect"
					@create-lead="onCreateLead" />

				<div v-if="prospectStore.cachedAt" class="prospect-widget__cache-info">
					{{ t('pipelinq', 'Last updated: {time}', { time: formatTime(prospectStore.cachedAt) }) }}
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ProspectCard from './ProspectCard.vue'
import { useProspectStore } from '../store/modules/prospect.js'

export default {
	name: 'ProspectWidget',
	components: {
		NcButton,
		NcLoadingIcon,
		Refresh,
		ProspectCard,
	},
	data() {
		return {
			expanded: false,
		}
	},
	computed: {
		prospectStore() {
			return useProspectStore()
		},
	},
	mounted() {
		this.prospectStore.fetchProspects()
	},
	methods: {
		async refresh() {
			await this.prospectStore.fetchProspects(true)
		},
		async onCreateLead(prospect) {
			const result = await this.prospectStore.createLeadFromProspect(prospect)
			if (result.error) {
				showError(result.error)
			} else {
				showSuccess(t('pipelinq', 'Lead created from {name}', { name: prospect.tradeName }))
				if (result.lead?.id) {
					this.$router.push({ name: 'LeadDetail', params: { id: result.lead.id } })
				}
			}
		},
		formatTime(dateStr) {
			if (!dateStr) return ''
			try {
				return new Date(dateStr).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' })
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.prospect-widget {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	margin-bottom: 24px;
}

.prospect-widget__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	cursor: pointer;
	user-select: none;
}

.prospect-widget__header:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.prospect-widget__title {
	display: flex;
	align-items: center;
	gap: 8px;
}

.prospect-widget__title h3 {
	margin: 0;
	font-size: 15px;
}

.prospect-count {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.prospect-widget__actions {
	display: flex;
	align-items: center;
	gap: 4px;
}

.expand-icon {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.prospect-widget__preview {
	padding: 0 16px 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.prospect-widget__body {
	padding: 0 16px 16px;
}

.prospect-widget__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.prospect-widget__setup,
.prospect-widget__empty,
.prospect-widget__error {
	text-align: center;
	padding: 20px;
	color: var(--color-text-maxcontrast);
}

.prospect-widget__cache-info {
	text-align: center;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 8px;
}

.icon-spinning {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>
