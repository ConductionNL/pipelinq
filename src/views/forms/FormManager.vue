<template>
	<div class="form-manager">
		<div class="form-header">
			<h2>{{ $t('pipelinq', 'Intake Forms') }}</h2>
			<NcButton type="primary" @click="$router.push({ name: 'FormNew' })">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ $t('pipelinq', 'New form') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="forms.length === 0"
			:name="t('pipelinq', 'No forms yet')"
			:description="t('pipelinq', 'Create intake forms to capture leads from your website.')">
			<template #icon>
				<FormTextboxPassword :size="64" />
			</template>
			<template #action>
				<NcButton type="primary" @click="$router.push({ name: 'FormNew' })">
					{{ $t('pipelinq', 'Create first form') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<table v-else class="form-table">
			<thead>
				<tr>
					<th>{{ $t('pipelinq', 'Name') }}</th>
					<th>{{ $t('pipelinq', 'Status') }}</th>
					<th>{{ $t('pipelinq', 'Submissions') }}</th>
					<th>{{ $t('pipelinq', 'Actions') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="form in forms" :key="form.id">
					<td>
						<router-link :to="{ name: 'FormDetail', params: { id: form.id } }">
							{{ form.name }}
						</router-link>
					</td>
					<td>
						<span :class="form.isActive ? 'status-active' : 'status-inactive'">
							{{ form.isActive ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive') }}
						</span>
					</td>
					<td>{{ form.submitCount || 0 }}</td>
					<td class="actions-cell">
						<NcButton type="tertiary" @click="showEmbedCode(form)">
							<template #icon>
								<CodeTags :size="20" />
							</template>
						</NcButton>
						<NcButton type="tertiary"
							@click="$router.push({ name: 'FormSubmissions', params: { id: form.id } })">
							<template #icon>
								<FormatListBulleted :size="20" />
							</template>
						</NcButton>
						<NcButton type="tertiary" @click="confirmDelete(form)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Embed code dialog -->
		<NcDialog v-if="embedDialog" :name="t('pipelinq', 'Embed Code')" @closing="embedDialog = false">
			<div class="embed-content">
				<h4>{{ $t('pipelinq', 'iframe') }}</h4>
				<textarea readonly
					class="embed-code"
					:value="embedCode.iframe"
					@click="$event.target.select()" />
				<h4>{{ $t('pipelinq', 'JavaScript') }}</h4>
				<textarea readonly
					class="embed-code"
					:value="embedCode.js"
					@click="$event.target.select()" />
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcDialog } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useObjectStore } from '../../store/store.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import FormTextboxPassword from 'vue-material-design-icons/FormTextboxPassword.vue'
import CodeTags from 'vue-material-design-icons/CodeTags.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'FormManager',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcDialog,
		Plus,
		FormTextboxPassword,
		CodeTags,
		FormatListBulleted,
		Delete,
	},
	data() {
		return {
			loading: false,
			forms: [],
			embedDialog: false,
			embedCode: { iframe: '', js: '' },
		}
	},
	mounted() {
		this.fetchForms()
	},
	methods: {
		async fetchForms() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('intakeForm')
				this.forms = result?.results || []
			} catch (e) {
				console.error('Failed to load forms', e)
			} finally {
				this.loading = false
			}
		},
		async showEmbedCode(form) {
			try {
				const response = await axios.get(generateUrl('/apps/pipelinq/api/forms/{id}/embed', { id: form.id }))
				this.embedCode = response.data
				this.embedDialog = true
			} catch (e) {
				console.error('Failed to get embed code', e)
			}
		},
		async confirmDelete(form) {
			const msg = this.t('pipelinq', 'Delete form "{name}"?', { name: form.name })
			if (confirm(msg)) {
				const objectStore = useObjectStore()
				await objectStore.deleteObject('intakeForm', form.id)
				this.fetchForms()
			}
		},
	},
}
</script>

<style scoped>
.form-manager {
	padding: 20px;
	max-width: 1200px;
}

.form-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.form-table {
	width: 100%;
	border-collapse: collapse;
}

.form-table th,
.form-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.form-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.status-active {
	color: var(--color-success);
	font-weight: bold;
}

.status-inactive {
	color: var(--color-text-maxcontrast);
}

.actions-cell {
	display: flex;
	gap: 4px;
}

.embed-content h4 {
	margin: 12px 0 4px;
}

.embed-code {
	width: 100%;
	height: 80px;
	font-family: monospace;
	font-size: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	resize: vertical;
}
</style>
