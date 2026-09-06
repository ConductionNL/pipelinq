<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Confirm one keyword proposal into a keyword target
  (marketing-search-intelligence).

  The dialog exists because confirming is a DECISION, not a save: the marketer
  says what they intend to do about the term, and only then does a record
  appear. A one-click "add" would put the same object in the register with
  nobody having chosen anything about it, which is exactly what "agents
  propose, people dispose" is meant to prevent from happening by accident.

  Its own file per ADR-004: a dialog written inline in the page would couple
  its lifecycle to the page's and could not be reused.

  @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Add as keyword target')"
		size="normal"
		data-testid="keyword-target-confirm"
		@closing="$emit('close')">
		<div class="keyword-confirm">
			<p class="keyword-confirm__term">{{ term }}</p>

			<NcSelect
				v-model="status"
				:options="statusOptions"
				:clearable="false"
				:inputLabel="t('pipelinq', 'What do you want to do with it')"
				label="label"
				data-testid="keyword-target-status" />

			<NcSelect
				v-model="intent"
				:options="intentOptions"
				:inputLabel="
					t('pipelinq', 'What is somebody typing this trying to do')
				"
				label="label"
				data-testid="keyword-target-intent" />

			<NcTextField
				v-model="targetPageRef"
				:label="t('pipelinq', 'Page that should win it')"
				placeholder="https://example.org/woo"
				data-testid="keyword-target-page" />

			<NcTextArea
				v-model="notes"
				:label="t('pipelinq', 'Notes')"
				:placeholder="
					t(
						'pipelinq',
						'Why this term matters, and what you decided about it.',
					)
				"
				data-testid="keyword-target-notes"
				resize="vertical" />

			<p v-if="error" class="keyword-confirm__error" role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || term === ''"
				data-testid="keyword-target-save"
				@click="save">
				{{ t('pipelinq', 'Add target') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { confirmPayload } from '../services/keywordIntel.js'

export default {
	name: 'KeywordTargetConfirmModal',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The proposal being confirmed. */
		proposal: {
			type: Object,
			required: true,
		},

		/** Which derivation proposed it. */
		kind: {
			type: String,
			default: 'manual',
		},
	},

	emits: ['close', 'confirmed'],

	data() {
		return {
			saving: false,
			error: '',
			status: null,
			intent: null,
			targetPageRef: '',
			notes: '',
		}
	},

	computed: {
		/**
		 * The term being confirmed.
		 *
		 * @return {string}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		term() {
			return String(this.proposal?.query || this.proposal?.term || '').trim()
		},

		/**
		 * What a marketer may decide about a term.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		statusOptions() {
			return [
				{ id: 'use-more', label: this.t('pipelinq', 'Use more') },
				{ id: 'use-less', label: this.t('pipelinq', 'Use less') },
				{ id: 'watch', label: this.t('pipelinq', 'Keep an eye on it') },
			]
		},

		/**
		 * What somebody typing the term is trying to do.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		intentOptions() {
			return [
				{
					id: 'informational',
					label: this.t('pipelinq', 'Find something out'),
				},
				{
					id: 'navigational',
					label: this.t('pipelinq', 'Reach a specific page'),
				},
				{ id: 'commercial', label: this.t('pipelinq', 'Compare options') },
				{
					id: 'transactional',
					label: this.t('pipelinq', 'Arrange something'),
				},
			]
		},
	},

	created() {
		this.status = this.statusOptions[0]
		this.targetPageRef = String(this.proposal?.topPage || '')
	},

	methods: {
		/**
		 * POST /api/marketing/keyword-targets. This is the only path in the
		 * product that creates a keyword target.
		 *
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/marketing/keyword-targets',
				)
				await axios.post(
					url,
					confirmPayload(
						this.proposal,
						{
							status: this.status?.id,
							intent: this.intent?.id,
							targetPageRef: this.targetPageRef,
							notes: this.notes,
						},
						this.kind,
					),
				)
				this.$emit('confirmed', this.term)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'The keyword target could not be added.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.keyword-confirm {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 16px 16px;
}

.keyword-confirm__term {
	font-weight: bold;
	font-size: 1.1em;
}

.keyword-confirm__error {
	color: var(--color-error);
}
</style>
