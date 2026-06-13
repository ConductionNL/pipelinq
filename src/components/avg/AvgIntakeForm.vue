<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-intake">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="successKenmerk" type="success">
			{{ t('pipelinq', 'Request registered') }}: {{ successKenmerk }}
		</NcNoteCard>

		<fieldset class="avg-intake__articles">
			<legend>{{ t('pipelinq', 'Which right does this request invoke?') }}</legend>
			<NcCheckboxRadioSwitch v-for="art in articles"
				:key="art"
				:model-value="form.artikel"
				:value="art"
				type="radio"
				name="avg-article"
				@update:model-value="form.artikel = art">
				{{ articleLabel(art) }}
			</NcCheckboxRadioSwitch>
		</fieldset>

		<label class="avg-intake__label" for="avg-vraag">{{ t('pipelinq', 'Your request') }}</label>
		<textarea id="avg-vraag"
			v-model="form.specifiekeVraag"
			class="avg-intake__textarea"
			:placeholder="t('pipelinq', 'Describe the request in your own words')" />

		<NcTextField :model-value="form.verzoekerNaam"
			:label="t('pipelinq', 'Name of the data subject')"
			@update:model-value="form.verzoekerNaam = $event" />

		<NcTextField :model-value="form.verzoekerBsn"
			:label="t('pipelinq', 'BSN')"
			:error="bsnTouched && !bsnValid"
			:helper-text="bsnTouched && !bsnValid ? t('pipelinq', 'Invalid BSN') : ''"
			@update:model-value="onBsn" />

		<NcCheckboxRadioSwitch :model-value="form.verzoekerBsnGeverifieerd"
			@update:model-value="form.verzoekerBsnGeverifieerd = $event">
			{{ t('pipelinq', 'BSN verified via BRP') }}
		</NcCheckboxRadioSwitch>

		<div class="avg-intake__actions">
			<NcButton type="primary" :disabled="!canSubmit || submitting" @click="submit">
				{{ t('pipelinq', 'Register request') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { ARTICLES, articleLabel, isValidBsn } from '../../utils/avg/avgLabels.js'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'AvgIntakeForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},
	data() {
		return {
			articles: ARTICLES,
			form: {
				artikel: '',
				specifiekeVraag: '',
				verzoekerNaam: '',
				verzoekerBsn: '',
				verzoekerBsnGeverifieerd: false,
				ingediendVia: 'handmatig',
			},
			bsnTouched: false,
			submitting: false,
			error: '',
			successKenmerk: '',
		}
	},
	computed: {
		/**
		 * Whether the entered BSN is structurally valid (or empty).
		 *
		 * @return {boolean} True when valid or empty.
		 */
		bsnValid() {
			return this.form.verzoekerBsn === '' || isValidBsn(this.form.verzoekerBsn)
		},
		/**
		 * Whether the form may be submitted.
		 *
		 * @return {boolean} True when an article is chosen and the BSN is valid.
		 */
		canSubmit() {
			return this.form.artikel !== '' && this.bsnValid
		},
	},
	methods: {
		articleLabel,
		/**
		 * Track BSN edits and mark the field as touched for validation display.
		 *
		 * @param {string} value The new BSN value.
		 */
		onBsn(value) {
			this.form.verzoekerBsn = value
			this.bsnTouched = true
		},
		/**
		 * Submit the intake form.
		 */
		async submit() {
			this.submitting = true
			this.error = ''
			this.successKenmerk = ''
			try {
				const { verzoek } = await avgApi.create({ ...this.form })
				this.successKenmerk = verzoek.kenmerk
				this.$emit('created', verzoek)
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('pipelinq', 'Could not register the request')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.avg-intake { display: flex; flex-direction: column; gap: 12px; max-width: 640px; }
.avg-intake__articles { display: flex; flex-direction: column; gap: 4px; border: none; }
.avg-intake__label { font-weight: bold; }
.avg-intake__textarea { width: 100%; min-height: 80px; }
.avg-intake__actions { margin-top: 8px; }
</style>
