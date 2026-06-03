<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Call disposition')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cti-disposition">
			<NcTextField :value.sync="subject"
				:label="t('pipelinq', 'Subject')"
				:placeholder="t('pipelinq', 'What was the call about?')"
				required />

			<NcSelect v-model="selectedOutcome"
				class="cti-disposition__outcome"
				:input-label="t('pipelinq', 'Outcome')"
				:options="outcomeOptions"
				:placeholder="t('pipelinq', 'Select an outcome')"
				label="label"
				:clearable="false" />

			<NcTextArea :value.sync="notes"
				class="cti-disposition__notes"
				:label="t('pipelinq', 'Notes')"
				:placeholder="t('pipelinq', 'Optional notes for this call')" />

			<p v-if="error" class="cti-disposition__error">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="saving || !canSave"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('pipelinq', 'Save & close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcTextField, NcTextArea, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CtiDispositionModal',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcTextField,
		NcTextArea,
		NcLoadingIcon,
	},
	props: {
		/** The contactmoment UUID this disposition belongs to. */
		contactmomentId: {
			type: String,
			required: true,
		},
	},
	emits: ['close', 'saved'],
	data() {
		return {
			subject: '',
			notes: '',
			selectedOutcome: null,
			saving: false,
			error: '',
		}
	},
	computed: {
		outcomeOptions() {
			return [
				{ id: 'resolved', label: t('pipelinq', 'Resolved') },
				{ id: 'callback', label: t('pipelinq', 'Callback') },
				{ id: 'escalated', label: t('pipelinq', 'Escalated') },
				{ id: 'wrong-number', label: t('pipelinq', 'Wrong number') },
				{ id: 'no-answer', label: t('pipelinq', 'No answer') },
				{ id: 'abandoned', label: t('pipelinq', 'Abandoned') },
			]
		},
		canSave() {
			return this.subject.trim() !== '' && this.selectedOutcome !== null
		},
	},
	methods: {
		t,
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/cti/contactmoment/{id}/disposition', { id: this.contactmomentId }),
					{
						subject: this.subject,
						outcome: this.selectedOutcome.id,
						notes: this.notes,
					},
				)
				showSuccess(t('pipelinq', 'Disposition saved'))
				this.$emit('saved', data.contactmoment)
				this.$emit('close')
			} catch (e) {
				this.error = t('pipelinq', 'Disposition could not be saved')
				showError(this.error)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.cti-disposition {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 320px;
}

.cti-disposition__error {
	color: var(--color-error);
}
</style>
