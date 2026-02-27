<template>
	<div class="create-overlay" @click.self="$emit('close')">
		<div class="create-dialog">
			<div class="create-dialog__header">
				<h3>{{ t('pipelinq', 'New Lead') }}</h3>
				<NcButton type="tertiary" @click="$emit('close')">
					✕
				</NcButton>
			</div>

			<div class="create-dialog__body">
				<LeadForm @save="onSave" @cancel="$emit('close')" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import LeadForm from './LeadForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'LeadCreateDialog',
	components: {
		NcButton,
		LeadForm,
	},
	emits: ['created', 'close'],
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	methods: {
		async onSave(formData) {
			const result = await this.objectStore.saveObject('lead', formData)
			if (result) {
				this.$emit('created', result.id)
			} else {
				const error = this.objectStore.getError('lead')
				showError(error?.message || t('pipelinq', 'Failed to create lead.'))
			}
		},
	},
}
</script>

<style scoped>
.create-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.create-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	width: 640px;
	max-width: 90vw;
	max-height: 85vh;
	overflow-y: auto;
}

.create-dialog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.create-dialog__header h3 {
	margin: 0;
}

.create-dialog__body {
	padding: 20px;
}
</style>
