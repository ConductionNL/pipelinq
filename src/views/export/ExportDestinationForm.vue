<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="
			isEdit
				? t('pipelinq', 'Edit destination')
				: t('pipelinq', 'New export destination')
		"
		:loading="loading"
		@back="goBack">
		<CnDetailCard :title="t('pipelinq', 'Destination')">
			<div class="export-form">
				<NcTextField v-model="model.name" :label="t('pipelinq', 'Name')" />
				<NcSelect
					:modelValue="selectedType"
					:options="typeOptions"
					:inputLabel="t('pipelinq', 'Type')"
					:placeholder="t('pipelinq', 'Choose a destination type…')"
					label="label"
					:clearable="false"
					@update:modelValue="(o) => (model.type = o ? o.id : '')" />
				<NcTextField
					v-model="model.connectorSourceId"
					:label="t('pipelinq', 'OpenConnector source ID')"
					:helperText="
						t(
							'pipelinq',
							'The OpenConnector source that holds the warehouse credentials',
						)
					" />
				<NcTextField
					v-model="model.pathTemplate"
					:label="t('pipelinq', 'Path template')"
					placeholder="exports/{schema}/{partition}" />
				<NcSelect
					:modelValue="selectedCompression"
					:options="compressionOptions"
					:inputLabel="t('pipelinq', 'Compression')"
					label="label"
					:clearable="false"
					@update:modelValue="
						(o) => (model.compression = o ? o.id : 'none')
					" />
				<NcTextField
					v-model="model.namingConvention"
					:label="t('pipelinq', 'Naming convention')"
					placeholder="{schema}_{run_id}_{timestamp}" />
				<NcCheckboxRadioSwitch
					v-model="model.encryptionEnabled"
					type="switch">
					{{ t('pipelinq', 'Encryption at rest') }}
				</NcCheckboxRadioSwitch>
			</div>

			<template #actions>
				<NcButton
					v-if="isEdit"
					variant="tertiary"
					:disabled="busy"
					@click="testConnection">
					{{ t('pipelinq', 'Test connection') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !model.name"
					@click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton variant="secondary" @click="goBack">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</template>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { exportApi } from '../../services/exportApi.js'
import { useObjectStore } from '../../store/modules/object.js'

const TYPES = ['s3', 'azure', 'gcs', 'bigquery', 'snowflake', 'sftp', 'postgres']
const COMPRESSIONS = ['none', 'gzip', 'snappy', 'zstd']

export default {
	name: 'ExportDestinationForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		CnDetailPage,
		CnDetailCard,
	},

	props: {
		exportDestinationId: {
			type: String,
			default: null,
		},

		id: {
			type: String,
			default: null,
		},
	},

	setup() {
		return { objectStore: useObjectStore() }
	},

	data() {
		return {
			loading: false,
			busy: false,
			model: {
				name: '',
				type: 's3',
				connectorSourceId: '',
				pathTemplate: '',
				compression: 'none',
				namingConvention: '',
				encryptionEnabled: false,
			},
		}
	},

	computed: {
		/**
		 * The resolved destination id from either prop name.
		 *
		 * @return {string|null} The destination UUID.
		 */
		destinationId() {
			return (
				this.exportDestinationId
				|| this.id
				|| this.$route?.params?.id
				|| null
			)
		},

		/**
		 * Whether the form is editing an existing destination.
		 *
		 * @return {boolean} True when editing.
		 */
		isEdit() {
			return !!this.destinationId
		},

		/**
		 * Destination type options.
		 *
		 * @return {Array<object>} The options.
		 */
		typeOptions() {
			return TYPES.map((id) => ({ id, label: id }))
		},

		/**
		 * The selected type option.
		 *
		 * @return {object|null} The option.
		 */
		selectedType() {
			return this.typeOptions.find((o) => o.id === this.model.type) || null
		},

		/**
		 * Compression options.
		 *
		 * @return {Array<object>} The options.
		 */
		compressionOptions() {
			return COMPRESSIONS.map((id) => ({ id, label: id }))
		},

		/**
		 * The selected compression option.
		 *
		 * @return {object|null} The option.
		 */
		selectedCompression() {
			return (
				this.compressionOptions.find((o) => o.id === this.model.compression)
				|| null
			)
		},
	},

	async mounted() {
		if (this.isEdit) {
			await this.load()
		}
	},

	methods: {
		/**
		 * Load the destination for editing.
		 */
		async load() {
			this.loading = true
			try {
				const existing = await this.objectStore.fetchObject(
					'exportDestination',
					this.destinationId,
				)
				if (existing) {
					this.model = { ...this.model, ...existing }
				}
			} catch (e) {
				showError(this.t('pipelinq', 'Could not load the destination'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the destination via the shared object store.
		 */
		async save() {
			this.busy = true
			try {
				const payload = { ...this.model }
				if (this.isEdit) {
					payload.id = this.destinationId
				}
				await this.objectStore.saveObject('exportDestination', payload)
				showSuccess(this.t('pipelinq', 'Destination saved'))
				this.$router.push({ name: 'ExportDestinations' })
			} catch (e) {
				showError(this.t('pipelinq', 'Could not save the destination'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Test connectivity to the (saved) destination.
		 */
		async testConnection() {
			this.busy = true
			try {
				const result = await exportApi.testDestination(this.destinationId)
				if (result.valid) {
					showSuccess(this.t('pipelinq', 'Connection valid'))
				} else {
					showError(this.t('pipelinq', 'Connection failed'))
				}
			} catch (e) {
				showError(this.t('pipelinq', 'Connection test failed'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Navigate back to the destination list.
		 */
		goBack() {
			this.$router.push({ name: 'ExportDestinations' })
		},
	},
}
</script>

<style scoped>
.export-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 560px;
}
</style>
