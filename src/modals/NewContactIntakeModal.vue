<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - NewContactIntakeModal collects the minimal data needed to create a new
  - contact on the back of an unmatched CTI screen-pop, laid out as board
  - PqGesprekNieuwContact: the call and its queue over the title, why the form
  - is open, name and phone, e-mail and organisation, notes, and what happens
  - after Create contact.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.2
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'New contact from incoming call')"
		:eyebrow="eyebrow"
		:subtitle="subtitle"
		width="form"
		data-testid="cti-intake-dialog"
		@closing="$emit('close')">
		<form class="cti-intake" @submit.prevent="submit">
			<CnNoteCard
				type="warning"
				class="cti-intake__wide"
				:text="t('pipelinq', 'No contact found for {number}. Fill in what the caller tells you; the number is already there.', { number: shownNumber })" />
			<div class="cti-intake__field">
				<label for="cti-intake-name" class="cti-intake__label">{{ t('pipelinq', 'Name') }}</label>
				<NcTextField
					id="cti-intake-name"
					v-model="form.name"
					:label="t('pipelinq', 'Name')"
					:placeholder="t('pipelinq', 'Full name')"
					required
					labelOutside
					data-testid="cti-intake-name" />
			</div>
			<div class="cti-intake__field">
				<label for="cti-intake-phone" class="cti-intake__label">{{ t('pipelinq', 'Phone') }}<span class="cti-intake__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
				<NcTextField
					id="cti-intake-phone"
					v-model="form.phone"
					:label="t('pipelinq', 'Phone')"
					:placeholder="t('pipelinq', 'Phone number')"
					labelOutside
					data-testid="cti-intake-phone" />
			</div>
			<div class="cti-intake__field">
				<label for="cti-intake-email" class="cti-intake__label">{{ t('pipelinq', 'E-mail') }}<span class="cti-intake__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
				<NcTextField
					id="cti-intake-email"
					v-model="form.email"
					:label="t('pipelinq', 'E-mail')"
					type="email"
					:placeholder="t('pipelinq', 'name@example.com')"
					labelOutside
					data-testid="cti-intake-email" />
			</div>
			<div class="cti-intake__field">
				<label for="cti-intake-organisation" class="cti-intake__label">{{ t('pipelinq', 'Organisation') }}<span class="cti-intake__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
				<NcTextField
					id="cti-intake-organisation"
					v-model="form.organisation"
					:label="t('pipelinq', 'Organisation')"
					:placeholder="t('pipelinq', 'Company or organisation')"
					labelOutside
					data-testid="cti-intake-organisation" />
			</div>
			<div class="cti-intake__field cti-intake__wide">
				<label for="cti-intake-notes" class="cti-intake__label">{{ t('pipelinq', 'Notes') }}<span class="cti-intake__optional"> {{ t('pipelinq', '(optional)') }}</span></label>
				<NcTextArea
					id="cti-intake-notes"
					v-model="form.notes"
					:label="t('pipelinq', 'Notes')"
					:placeholder="t('pipelinq', 'Why is this caller getting in touch?')"
					rows="3"
					labelOutside
					data-testid="cti-intake-notes" />
			</div>
			<p class="cti-intake__wide cti-intake__hint">
				{{ t('pipelinq', 'The new contact opens straight away, with this call as its first contact moment.') }}
			</p>
		</form>
		<template #actions>
			<NcButton data-testid="cti-intake-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid"
				data-testid="cti-intake-submit"
				@click="submit">
				<template #icon>
					<AccountPlus :size="20" />
				</template>
				{{ t('pipelinq', 'Create contact') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton, NcTextArea, NcTextField } from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlusOutline.vue'
import { readablePhone } from '../utils/readablePhone.js'

export default {
	name: 'NewContactIntakeModal',
	components: { AccountPlus, CnDialog, CnNoteCard, NcButton, NcTextArea, NcTextField },
	props: {
		prefillPhone: { type: String, default: '' },
		e164: { type: String, default: '' },
		/** The queue the call came in on, when the platform reported it. */
		queueName: { type: String, default: '' },
	},

	emits: ['close', 'submit'],
	data() {
		return {
			form: {
				name: '',
				phone: readablePhone(this.prefillPhone || this.e164),
				email: '',
				organisation: '',
				notes: '',
			},
		}
	},

	computed: {
		/** @return {string} The caller's number as people read it. */
		shownNumber() {
			return readablePhone(this.prefillPhone || this.e164)
		},

		/** @return {string} "Incoming call · queue Vergunningen". */
		eyebrow() {
			return [t('pipelinq', 'Incoming call'), this.queueLine].filter(Boolean).join(' · ')
		},

		/** @return {string} "Incoming call · 06 41 22 87 90 · queue Vergunningen". */
		subtitle() {
			return [t('pipelinq', 'Incoming call'), this.shownNumber, this.queueLine].filter(Boolean).join(' · ')
		},

		/** @return {string} The queue line, or '' when there is no queue. */
		queueLine() {
			return this.queueName ? t('pipelinq', 'queue {queue}', { queue: this.queueName }) : ''
		},

		valid() {
			return this.form.name.trim().length > 1
		},
	},

	methods: {
		submit() {
			if (!this.valid) {
				return
			}
			this.$emit('submit', { ...this.form, phone: this.form.phone.trim() || this.e164 || this.prefillPhone })
		},
	},
}
</script>

<style scoped>
.cti-intake {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.cti-intake__wide {
	grid-column: 1 / -1;
}

.cti-intake__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.cti-intake__label {
	font-size: 14px;
	font-weight: 600;
}

.cti-intake__optional {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.cti-intake__hint {
	margin: 0;
	font-size: 13px;
	line-height: normal;
	color: var(--color-text-maxcontrast);
}
</style>
