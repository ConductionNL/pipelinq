<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - LogVisitDialog asks for the two things worth writing down after a visit:
  - a one-line note and an optional follow-up day. It emits them; the parent
  - owns the store writes. Its own file because a modal is never written
  - inline in its parent (ADR-004).
  -
  - @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Log a visit')"
		size="small"
		@closing="$emit('close')">
		<form
			class="log-visit"
			data-testid="log-visit-form"
			@submit.prevent="submit">
			<label class="log-visit__label" for="log-visit-note">{{
				t('pipelinq', 'What happened?')
			}}</label>
			<textarea
				id="log-visit-note"
				v-model="note"
				class="log-visit__note"
				rows="3"
				required />
			<label class="log-visit__label" for="log-visit-follow-up">{{
				t('pipelinq', 'Follow up on (optional)')
			}}</label>
			<input
				id="log-visit-follow-up"
				v-model="followUpDate"
				class="log-visit__date"
				type="date" />
			<p v-if="error" class="log-visit__error" role="alert">
				{{ error }}
			</p>
		</form>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || !note.trim()"
				@click="submit">
				{{ t('pipelinq', 'Save visit') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'LogVisitDialog',
	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/** True while the parent is saving. */
		saving: {
			type: Boolean,
			default: false,
		},

		/** Error text from the last save attempt. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			note: '',
			followUpDate: '',
		}
	},

	methods: {
		/**
		 * Emit the note and the follow-up day when a note is present.
		 *
		 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
		 */
		submit() {
			if (!this.note.trim() || this.saving) return
			this.$emit('save', {
				note: this.note.trim(),
				followUpDate: this.followUpDate,
			})
		},
	},
}
</script>

<style scoped>
.log-visit {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.log-visit__label {
	font-weight: 600;
}

.log-visit__note,
.log-visit__date {
	width: 100%;
	min-height: 44px;
	font-size: 16px;
}

.log-visit__error {
	color: var(--color-error-text, var(--color-error));
}
</style>
