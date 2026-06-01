<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div class="cash-shift-form">
		<h2>{{ t('pipelinq', 'Nieuwe kassalade-shift openen') }}</h2>
		<form class="cash-shift-form__body" @submit.prevent="submit">
			<div class="form-field">
				<NcTextField
					v-model="form.drawer"
					:label="t('pipelinq', 'Kassalade')"
					:placeholder="t('pipelinq', 'bijv. kassa-01')"
					required />
			</div>
			<div class="form-field">
				<NcTextField
					v-model="form.floatAmount"
					type="number"
					:label="t('pipelinq', 'Openingsbedrag (€)')"
					:placeholder="'100.00'"
					min="0"
					step="0.01"
					required />
				<small v-if="floatError" class="form-field__error">{{ floatError }}</small>
			</div>
			<div class="form-field">
				<NcTextField
					v-model="form.reference"
					:label="t('pipelinq', 'Referentie (optioneel)')"
					:placeholder="t('pipelinq', 'Automatisch gegenereerd indien leeg')" />
			</div>
			<div class="form-field">
				<NcTextArea
					v-model="form.notes"
					:label="t('pipelinq', 'Notities (optioneel)')"
					:placeholder="t('pipelinq', 'Bijv. openingsfloat geverifieerd door manager')"
					rows="2" />
			</div>
			<div class="form-actions">
				<NcButton type="secondary" @click="$router.push({ name: 'CashShifts' })">
					{{ t('pipelinq', 'Annuleren') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="busy || !form.drawer || !form.floatAmount"
					native-type="submit">
					{{ t('pipelinq', 'Shift openen') }}
				</NcButton>
			</div>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcNoteCard, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'CashShiftForm',
	components: {
		NcButton,
		NcNoteCard,
		NcTextArea,
		NcTextField,
	},
	data() {
		return {
			busy: false,
			error: '',
			floatError: '',
			form: {
				drawer: '',
				floatAmount: '',
				reference: '',
				notes: '',
			},
		}
	},
	methods: {
		/**
		 * Submit the open-shift form.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			this.error = ''
			this.floatError = ''

			const amount = parseFloat(this.form.floatAmount)
			if (isNaN(amount) || amount < 0) {
				this.floatError = this.t('pipelinq', 'Openingsbedrag verplicht')
				return
			}

			this.busy = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos-shifts/open')
				const response = await axios.post(url, {
					drawer: this.form.drawer,
					floatAmount: amount,
					reference: this.form.reference,
					notes: this.form.notes,
				})
				showSuccess(this.t('pipelinq', 'Shift geopend'))
				const shiftId = response.data?.shift?.id
				if (shiftId) {
					this.$router.push({ name: 'CashShiftDetail', params: { id: shiftId } })
				} else {
					this.$router.push({ name: 'CashShifts' })
				}
			} catch (error) {
				this.error = error?.response?.data?.error ?? this.t('pipelinq', 'Fout bij openen van shift')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.cash-shift-form {
	max-width: 520px;
	padding: 1.5rem;
}

.cash-shift-form h2 {
	margin-bottom: 1.5rem;
}

.cash-shift-form__body {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.form-field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.form-field__error {
	color: var(--color-error, #e53935);
	font-size: 0.85rem;
}

.form-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
	margin-top: 0.5rem;
}
</style>
