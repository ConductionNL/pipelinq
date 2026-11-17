<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The Merges section on a client or contact (clients-reversible-merge, task 3.2).
It lists the merges in which this record was the one that stayed. While a
merge is inside its reversal window it offers Undo, which asks OpenRegister to
reverse it; after the window it shows the date the merge became final and no
Undo. A record that never survived a merge shows nothing but a sentence.

@spec openspec/changes/clients-reversible-merge
-->
<template>
	<div class="record-merges">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="record-merges__error" role="alert">
			{{ error }}
		</p>
		<p v-else-if="merges.length === 0" class="record-merges__empty" data-testid="record-merges-empty">
			{{ t('pipelinq', 'No other record was merged into this one.') }}
		</p>
		<ul v-else class="record-merges__list">
			<li
				v-for="merge in rows"
				:key="merge.id"
				class="record-merges__item"
				:data-testid="'record-merge-' + merge.id">
				<span class="record-merges__what">
					{{ merge.label }}
				</span>
				<span class="record-merges__when" :data-state="merge.state">
					{{ merge.status }}
				</span>
				<span v-if="merge.state === 'open'" class="record-merges__actions">
					<template v-if="confirming === merge.id">
						<NcButton
							variant="error"
							:disabled="busy"
							data-testid="record-merge-confirm"
							@click="undo(merge.id)">
							{{ t('pipelinq', 'Undo the merge') }}
						</NcButton>
						<NcButton :disabled="busy" @click="confirming = ''">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</template>
					<NcButton
						v-else
						:disabled="busy"
						data-testid="record-merge-undo"
						@click="confirming = merge.id">
						{{ t('pipelinq', 'Undo') }}
					</NcButton>
				</span>
			</li>
		</ul>
		<p v-if="notice" class="record-merges__notice" role="status">
			{{ notice }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { formatDateFull } from '../../services/localeUtils.js'
import { fetchMergesInto, mergedAwayName, mergeFinalAt, mergeState, reverseMergeOperation } from '../../services/mergeOperations.js'
import { sectionObjectId } from '../../utils/sectionContext.js'

export default {
	name: 'RecordMergesSection',
	components: { NcButton, NcLoadingIcon },
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The record's uuid (resolved from `@objectId`). */
		recordId: {
			type: String,
			default: '',
		},

		/** The schema's `x-openregister-merge.reversalWindowDays`. */
		reversalWindowDays: {
			type: Number,
			default: 30,
		},
	},

	emits: ['reversed'],

	data() {
		return {
			loading: false,
			busy: false,
			error: '',
			notice: '',
			confirming: '',
			merges: [],
			now: new Date(),
		}
	},

	computed: {
		/**
		 * The record id from the prop or the section context.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/clients-reversible-merge
		 */
		resolvedId() {
			return this.recordId || sectionObjectId(this.cnSectionContext)
		},

		/**
		 * Each merge with what to show for it.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/clients-reversible-merge
		 */
		rows() {
			return this.merges.map((operation) => {
				const state = mergeState(operation, this.reversalWindowDays, this.now)
				const name = mergedAwayName(operation)
				const mergedOn = formatDateFull(operation.mergedAt)
				return {
					id: operation.id || operation.uuid,
					state,
					label: name
						? this.t('pipelinq', '{name} merged into this record on {date}', { name, date: mergedOn })
						: this.t('pipelinq', 'A record merged into this one on {date}', { date: mergedOn }),
					status: this.statusText(operation, state),
				}
			})
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/clients-reversible-merge
		 */
		resolvedId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * What a merge's state means for the reader.
		 *
		 * @param {object} operation The merge operation.
		 * @param {string} state Its state.
		 * @return {string} The line.
		 * @spec openspec/changes/clients-reversible-merge
		 */
		statusText(operation, state) {
			if (state === 'reversed') {
				return this.t('pipelinq', 'Undone on {date}', { date: formatDateFull(operation.reversedAt) })
			}
			const finalAt = mergeFinalAt(operation.mergedAt, this.reversalWindowDays)
			const date = finalAt ? formatDateFull(finalAt.toISOString()) : ''
			if (state === 'open') {
				return this.t('pipelinq', 'Can be undone until {date}', { date })
			}
			return date
				? this.t('pipelinq', 'Final since {date}', { date })
				: this.t('pipelinq', 'Final')
		},

		/**
		 * Read the merges this record survived.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-reversible-merge
		 */
		async load() {
			if (!this.resolvedId) {
				this.merges = []
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.merges = await fetchMergesInto(this.resolvedId)
				this.now = new Date()
			} catch {
				this.merges = []
				this.error = this.t('pipelinq', 'The merges of this record could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Undo one merge, then read the list again.
		 *
		 * @param {string} operationId The merge operation's uuid.
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-reversible-merge
		 */
		async undo(operationId) {
			this.busy = true
			this.notice = ''
			try {
				await reverseMergeOperation(operationId)
				this.notice = this.t('pipelinq', 'The merge was undone. Both records exist again with their own links.')
				this.$emit('reversed', operationId)
			} catch {
				this.notice = this.t('pipelinq', 'The merge could not be undone. The reversal window may have passed.')
			} finally {
				this.busy = false
				this.confirming = ''
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
.record-merges__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.record-merges__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.record-merges__what {
	flex: 1 1 auto;
}

.record-merges__when {
	color: var(--color-text-maxcontrast);
}

.record-merges__actions {
	display: flex;
	gap: var(--default-grid-baseline);
}

.record-merges__error {
	color: var(--color-error-text);
}
</style>
