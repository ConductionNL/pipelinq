<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-denial">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="saved" type="success">
			{{ t('pipelinq', 'Denial recorded. The handler must sign before sending to the data subject.') }}
		</NcNoteCard>

		<fieldset class="avg-denial__scope">
			<NcCheckboxRadioSwitch :model-value="form.weigering"
				value="geheel"
				type="radio"
				name="avg-denial-scope"
				@update:model-value="form.weigering = 'geheel'">
				{{ t('pipelinq', 'Fully denied') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch :model-value="form.weigering"
				value="gedeeltelijk"
				type="radio"
				name="avg-denial-scope"
				@update:model-value="form.weigering = 'gedeeltelijk'">
				{{ t('pipelinq', 'Partially denied') }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<label class="avg-denial__label">{{ t('pipelinq', 'GDPR Art. 23 ground') }}</label>
		<NcSelect v-model="grondOption"
			:input-label="t('pipelinq', 'GDPR Art. 23 ground')"
			:options="grondOptions"
			label="label"
			:clearable="false" />

		<label class="avg-denial__label">{{ t('pipelinq', 'Motivation') }}</label>
		<textarea v-model="form.toelichtingAvg23"
			class="avg-denial__textarea"
			:placeholder="t('pipelinq', 'Explain the chosen ground (min. 100 characters)')" />
		<span class="avg-denial__counter" :class="{ 'avg-denial__counter--ok': motivationOk }">
			{{ form.toelichtingAvg23.length }} / 100
		</span>

		<NcTextField :model-value="form.verwijzingAp"
			:label="t('pipelinq', 'AP complaint procedure URL')"
			@update:model-value="form.verwijzingAp = $event" />

		<div class="avg-denial__actions">
			<NcButton type="primary" :disabled="!canSubmit || submitting" @click="submit">
				{{ t('pipelinq', 'Record denial') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { DENIAL_GROUNDS } from '../../utils/avg/avgLabels.js'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'DenialForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	props: {
		/** The parent request id. */
		verzoekId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			form: {
				weigering: 'geheel',
				toelichtingAvg23: '',
				verwijzingAp: '',
			},
			grondOption: { value: DENIAL_GROUNDS[0], label: DENIAL_GROUNDS[0] },
			submitting: false,
			saved: false,
			error: '',
		}
	},
	computed: {
		/**
		 * The art. 23 ground options.
		 *
		 * @return {Array<object>} The options.
		 */
		grondOptions() {
			return DENIAL_GROUNDS.map((g) => ({ value: g, label: g }))
		},
		/**
		 * Whether the motivation meets the minimum length.
		 *
		 * @return {boolean} True when long enough.
		 */
		motivationOk() {
			return this.form.toelichtingAvg23.length >= 100
		},
		/**
		 * Whether the denial may be submitted.
		 *
		 * @return {boolean} True when valid.
		 */
		canSubmit() {
			return this.motivationOk
		},
	},
	methods: {
		/**
		 * Submit the denial draft.
		 */
		async submit() {
			this.submitting = true
			this.error = ''
			this.saved = false
			try {
				await avgApi.deny(this.verzoekId, {
					weigering: this.form.weigering,
					grond: this.grondOption.value,
					toelichtingAvg23: this.form.toelichtingAvg23,
					verwijzingAp: this.form.verwijzingAp,
				})
				this.saved = true
				this.$emit('denied')
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('pipelinq', 'Could not record the denial')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.avg-denial { display: flex; flex-direction: column; gap: 8px; max-width: 640px; }
.avg-denial__scope { display: flex; gap: 16px; border: none; }
.avg-denial__label { font-weight: bold; }
.avg-denial__textarea { width: 100%; min-height: 100px; }
.avg-denial__counter { color: var(--color-error); font-size: 0.85em; }
.avg-denial__counter--ok { color: var(--color-success); }
.avg-denial__actions { margin-top: 8px; }
</style>
