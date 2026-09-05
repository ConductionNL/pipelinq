<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS tender type admin list (pos-split-tender REQ-PST-001).
  -
  - @spec openspec/changes/pos-split-tender/tasks.md#8.1
  -->
<template>
	<div class="pos-tender-type-list">
		<!-- No heading of its own: the host NcSettingsSection (PosTenderTypeManager,
		     on the admin page) already names and describes this surface. -->
		<div class="pos-tender-type-list__header">
			<div class="pos-tender-type-list__title" />
			<div class="pos-tender-type-list__actions">
				<NcButton :disabled="loading" @click="refresh">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
				<NcButton variant="primary" @click="createNew">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'New tender type') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<table
			v-else-if="tenderTypes.length > 0"
			class="pos-tender-type-list__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Name') }}</th>
					<th scope="col">{{ t('pipelinq', 'Code') }}</th>
					<th scope="col">{{ t('pipelinq', 'GL account') }}</th>
					<th scope="col">{{ t('pipelinq', 'Flags') }}</th>
					<th scope="col">{{ t('pipelinq', 'Active') }}</th>
					<th scope="col">{{ t('pipelinq', 'Sort') }}</th>
					<th scope="col" class="pos-tender-type-list__col-actions">
						{{ t('pipelinq', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="type in tenderTypes" :key="type.id || type.code">
					<td>{{ type.name }}</td>
					<td>
						<code>{{ type.code }}</code>
					</td>
					<td>{{ type.glAccount }}</td>
					<td>
						<span
							v-if="type.requiresReference"
							class="pos-tender-type-list__flag"
							>{{ t('pipelinq', 'Ref') }}</span
						>
						<span
							v-if="type.requiresPin"
							class="pos-tender-type-list__flag"
							>{{ t('pipelinq', 'PIN') }}</span
						>
						<span
							v-if="type.allowsChange"
							class="pos-tender-type-list__flag"
							>{{ t('pipelinq', 'Change') }}</span
						>
					</td>
					<td>
						<span
							v-if="type.isActive"
							class="pos-tender-type-list__badge pos-tender-type-list__badge--on">
							{{ t('pipelinq', 'Active') }}
						</span>
						<span
							v-else
							class="pos-tender-type-list__badge pos-tender-type-list__badge--off">
							{{ t('pipelinq', 'Inactive') }}
						</span>
					</td>
					<td class="pos-tender-type-list__col-sort">
						{{ type.sortOrder || 0 }}
					</td>
					<td class="pos-tender-type-list__col-actions">
						<NcButton @click="editType(type)">
							<template #icon>
								<Pencil :size="20" />
							</template>
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton variant="error" @click="deleteType(type)">
							<template #icon>
								<Delete :size="20" />
							</template>
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<p v-else class="pos-tender-type-list__empty">
			{{ t('pipelinq', 'No tender types defined yet.') }}
		</p>

		<p v-if="errorMessage" class="pos-tender-type-list__error" role="alert">
			{{ errorMessage }}
		</p>

		<PosTenderTypeFormDialog
			v-if="showForm"
			:tenderType="editingType"
			@close="onFormClose"
			@saved="onFormSaved" />
		<ConfirmDialog
			v-if="pendingDeleteType"
			:name="t('pipelinq', 'Delete tender type')"
			:message="deleteTypeMessage"
			:confirmLabel="t('pipelinq', 'Delete')"
			@confirm="performDeleteType"
			@cancel="pendingDeleteType = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'
import PosTenderTypeFormDialog from '../../modals/PosTenderTypeFormDialog.vue'

export default {
	name: 'PosTenderTypeList',
	components: {
		ConfirmDialog,
		NcButton,
		NcLoadingIcon,
		Refresh,
		Plus,
		Pencil,
		Delete,
		PosTenderTypeFormDialog,
	},

	data() {
		return {
			tenderTypes: [],
			loading: false,
			showForm: false,
			editingType: null,
			errorMessage: '',
			pendingDeleteType: null,
		}
	},

	computed: {
		/**
		 * Built here rather than inline in the template so the t() key stays
		 * byte-identical to the one the old window.confirm used. Escaping the
		 * quotes as &quot; in the attribute would have minted a NEW key and
		 * orphaned every existing translation of this string.
		 *
		 * @return {string} The confirmation message.
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		deleteTypeMessage() {
			if (!this.pendingDeleteType) {
				return ''
			}
			return t(
				'pipelinq',
				'Delete tender type "{name}"? Active tenders referencing this type block deletion.',
				{ name: this.pendingDeleteType.name },
			)
		},
	},

	async mounted() {
		await this.refresh()
	},

	methods: {
		async refresh() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/tender-types')
				const response = await axios.get(url)
				const results = response?.data?.results || []
				this.tenderTypes = results
					.slice()
					.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to load tender types')
			} finally {
				this.loading = false
			}
		},

		createNew() {
			this.editingType = null
			this.showForm = true
		},

		/**
		 * Open the edit form for a tender type.
		 *
		 * @param {object} type The tender type to edit.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		editType(type) {
			this.editingType = { ...type }
			this.showForm = true
		},

		/**
		 * Open the delete confirmation for a tender type.
		 *
		 * @param {object} type The tender type to delete.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		deleteType(type) {
			this.pendingDeleteType = type
		},

		/**
		 * Delete the pending tender type once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-split-tender/tasks.md#8.1
		 */
		async performDeleteType() {
			const type = this.pendingDeleteType
			this.pendingDeleteType = null
			if (!type) {
				return
			}
			try {
				const id = this.idOf(type)
				const url = generateUrl('/apps/pipelinq/api/pos/tender-types/{id}', {
					id,
				})
				await axios.delete(url)
				showSuccess(t('pipelinq', 'Tender type deleted'))
				await this.refresh()
			} catch (error) {
				const msg =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to delete tender type')
				showError(msg)
			}
		},

		onFormClose() {
			this.showForm = false
			this.editingType = null
		},

		async onFormSaved() {
			this.showForm = false
			this.editingType = null
			await this.refresh()
		},

		idOf(type) {
			if (type?.['@self']?.id) {
				return type['@self'].id
			}
			return type?.id || type?.uuid || ''
		},
	},
}
</script>

<style scoped>
.pos-tender-type-list {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 16px;
}

.pos-tender-type-list__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.pos-tender-type-list__title h2 {
	margin: 0 0 4px;
}

.pos-tender-type-list__title p {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.pos-tender-type-list__actions {
	display: flex;
	gap: 8px;
}

.pos-tender-type-list__table {
	width: 100%;
	border-collapse: collapse;
}

.pos-tender-type-list__table th {
	text-align: start;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
	padding: 8px;
}

.pos-tender-type-list__table td {
	border-bottom: 1px solid var(--color-border);
	padding: 8px;
	vertical-align: middle;
}

.pos-tender-type-list__col-actions {
	text-align: end;
	white-space: nowrap;
}

.pos-tender-type-list__col-actions :deep(.button-vue) {
	margin-inline-start: 4px;
}

.pos-tender-type-list__col-sort {
	text-align: end;
}

.pos-tender-type-list__flag {
	display: inline-block;
	margin-inline-end: 4px;
	padding: 2px 6px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 11px;
}

.pos-tender-type-list__badge {
	display: inline-block;
	padding: 2px 6px;
	border-radius: var(--border-radius);
	font-size: 11px;
}

.pos-tender-type-list__badge--on {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.pos-tender-type-list__badge--off {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.pos-tender-type-list__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.pos-tender-type-list__error {
	color: var(--color-error);
}
</style>
