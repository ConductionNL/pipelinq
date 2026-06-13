<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-redaction">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcEmptyContent v-if="items.length === 0"
			:name="t('pipelinq', 'No evidence to redact')" />

		<div v-for="item in items" :key="rowKey(item)" class="avg-redaction__item">
			<div class="avg-redaction__head">
				<strong>{{ item.bronApp }} — {{ item.categorie }}</strong>
				<span v-if="item.geredigeerd" class="avg-redaction__done">
					{{ t('pipelinq', 'Redacted') }}
				</span>
			</div>
			<pre class="avg-redaction__preview">{{ item.inhoudPreview }}</pre>
			<NcButton @click="openRedact(item)">
				{{ t('pipelinq', 'Redact a field') }}
			</NcButton>
		</div>

		<AvgRedactionDialog v-if="active"
			:bewijs-item-id="active.id"
			:veldpad="active.veldpad"
			:own-data-warning="active.ownData"
			@close="active = null"
			@confirm="submitRedaction" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import AvgRedactionDialog from '../../modals/AvgRedactionDialog.vue'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'RedactionEditor',
	components: {
		AvgRedactionDialog,
		NcButton,
		NcEmptyContent,
		NcNoteCard,
	},
	props: {
		/** The parent request id. */
		verzoekId: {
			type: String,
			required: true,
		},
		/** The evidence items to redact. */
		items: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			active: null,
			error: '',
		}
	},
	methods: {
		/**
		 * Stable key for an evidence item.
		 *
		 * @param {object} item The item.
		 * @return {string} The key.
		 */
		rowKey(item) {
			return item.id || item['@self']?.id || item.bronObject
		},
		/**
		 * Open the redaction dialog for an item.
		 *
		 * @param {object} item The item.
		 */
		openRedact(item) {
			const veldpad = window.prompt(this.t('pipelinq', 'JSONPath of the field to redact (e.g. $.handhaver.naam)'), '$.')
			if (!veldpad) {
				return
			}
			this.active = {
				id: this.rowKey(item),
				veldpad,
				ownData: false,
			}
		},
		/**
		 * Submit a redaction; surfaces the own-data guard error from the server.
		 *
		 * @param {object} payload The redaction payload.
		 */
		async submitRedaction(payload) {
			this.error = ''
			try {
				await avgApi.redact(this.verzoekId, payload)
				this.active = null
				this.$emit('redacted')
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('pipelinq', 'Redaction failed')
				if (this.active) {
					this.active.ownData = true
				}
			}
		},
	},
}
</script>

<style scoped>
.avg-redaction { display: flex; flex-direction: column; gap: 12px; }
.avg-redaction__item { border: 1px solid var(--color-border); border-radius: var(--border-radius); padding: 8px; }
.avg-redaction__head { display: flex; justify-content: space-between; }
.avg-redaction__done { color: var(--color-success); }
.avg-redaction__preview { white-space: pre-wrap; word-break: break-word; background: var(--color-background-hover); padding: 6px; border-radius: var(--border-radius); }
</style>
