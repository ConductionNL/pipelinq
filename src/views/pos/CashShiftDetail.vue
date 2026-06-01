<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="shift.reference || t('pipelinq', 'Kassalade-shift')"
		:subtitle="t('pipelinq', 'POS kassaladebeheer')"
		:back-route="{ name: 'CashShifts' }"
		:back-label="t('pipelinq', 'Terug naar overzicht')"
		:loading="loading"
		:sidebar="!loading"
		object-type="pipelinq_cashShift"
		:object-id="shiftId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<!-- Close shift button: visible when status = open -->
			<NcButton v-if="canCount"
				type="primary"
				:disabled="busy"
				@click="showCountModal = true">
				{{ t('pipelinq', 'Shift afsluiten en tellen') }}
			</NcButton>
			<!-- Approve diff button: visible to managers when shift is closed -->
			<NcButton v-if="canApproveDiff"
				type="success"
				:disabled="busy"
				@click="confirmApproveDiff">
				{{ t('pipelinq', 'Goedkeuren') }}
			</NcButton>
			<!-- Reject diff button: visible to managers when shift is closed -->
			<NcButton v-if="canRejectDiff"
				type="error"
				:disabled="busy"
				@click="showRejectModal = true">
				{{ t('pipelinq', 'Afwijzen') }}
			</NcButton>
		</template>

		<!-- 1. Float declaration panel -->
		<CnDetailCard :title="t('pipelinq', 'Openingsbedrag')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Referentie') }}</label>
					<span>{{ shift.reference || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Kassalade') }}</label>
					<span>{{ shift.drawer || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Kassier') }}</label>
					<span>{{ shift.operator || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Manager') }}</label>
					<span>{{ shift.managedBy || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Openingsbedrag') }}</label>
					<span>{{ formatCurrency(shift.floatAmount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Geopend om') }}</label>
					<span>{{ formatDate(shift.floatAt) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge :status="shift.status" :label="statusLabel(shift.status)" />
				</div>
				<div v-if="shift.closedAt" class="info-field">
					<label>{{ t('pipelinq', 'Gesloten om') }}</label>
					<span>{{ formatDate(shift.closedAt) }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- 2. Drops panel -->
		<CnDetailCard :title="t('pipelinq', 'Kassaafdrachtregels')">
			<div v-if="shift.status === 'open'" class="drops-panel__add">
				<NcButton type="secondary" @click="showDropModal = true">
					{{ t('pipelinq', 'Geld verwijderen') }}
				</NcButton>
			</div>
			<table v-if="drops.length > 0" class="cash-shift-detail__drops">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Tijdstip') }}</th>
						<th>{{ t('pipelinq', 'Bedrag') }}</th>
						<th>{{ t('pipelinq', 'Reden') }}</th>
						<th>{{ t('pipelinq', 'Uitgevoerd door') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="drop in drops" :key="drop.id">
						<td>{{ formatDate(drop.droppedAt) }}</td>
						<td>{{ formatCurrency(drop.amount) }}</td>
						<td>{{ reasonLabel(drop.reason) }}</td>
						<td>{{ drop.droppedBy }}</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="empty-state">
				{{ t('pipelinq', 'Geen afdrachtregels geregistreerd') }}
			</p>
		</CnDetailCard>

		<!-- 3. Count entry panel (visible when status = open) -->
		<CnDetailCard v-if="shift.status === 'open'" :title="t('pipelinq', 'Kassatelling')">
			<p class="blind-count-instruction">
				{{ t('pipelinq', 'Voer het getelde contante bedrag in de kassalade in (blind count — geen verwacht bedrag zichtbaar).') }}
			</p>
			<NcButton type="primary" :disabled="busy" @click="showCountModal = true">
				{{ t('pipelinq', 'Shift afsluiten en tellen') }}
			</NcButton>
		</CnDetailCard>

		<!-- 4. Diff panel (visible when status = closed or reconciled) -->
		<CnDetailCard
			v-if="shift.status === 'closed' || shift.status === 'reconciled'"
			:title="t('pipelinq', 'Kassaverschil')">
			<div v-if="diff" class="diff-panel">
				<div class="info-grid">
					<div class="info-field">
						<label>{{ t('pipelinq', 'Verwacht bedrag') }}</label>
						<span>{{ formatCurrency(diff.expectedAmount) }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Geteld bedrag') }}</label>
						<span>{{ formatCurrency(diff.actualAmount) }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Verschil') }}</label>
						<span :class="diffClass">{{ formatCurrency(diff.diffAmount) }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Percentage') }}</label>
						<span :class="diffClass">
							{{ diff.diffPercentage !== null && diff.diffPercentage !== undefined
								? diff.diffPercentage.toFixed(2) + '%'
								: t('pipelinq', 'N/A (verwacht bedrag is €0)') }}
						</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Tolerantie') }}</label>
						<span :class="toleranceClass">
							{{ diff.withinTolerance
								? t('pipelinq', 'Binnen tolerantie')
								: t('pipelinq', 'Buiten tolerantie') }}
						</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Status') }}</label>
						<CnStatusBadge :status="diff.status" :label="diffStatusLabel(diff.status)" />
					</div>
					<div v-if="diff.approvedBy" class="info-field">
						<label>{{ t('pipelinq', 'Afgehandeld door') }}</label>
						<span>{{ diff.approvedBy }}</span>
					</div>
					<div v-if="diff.approvedAt" class="info-field">
						<label>{{ t('pipelinq', 'Afgehandeld om') }}</label>
						<span>{{ formatDate(diff.approvedAt) }}</span>
					</div>
				</div>
				<div v-if="!diff.withinTolerance && diff.status === 'pending'" class="diff-panel__warning">
					<NcNoteCard type="warning">
						{{ t('pipelinq', 'Verschil BUITEN tolerantie ({pct}%); manager-goedkeuring vereist', { pct: diff.diffPercentage?.toFixed(2) }) }}
					</NcNoteCard>
				</div>
				<div v-else-if="diff.withinTolerance && diff.status === 'pending'" class="diff-panel__info">
					<NcNoteCard type="info">
						{{ t('pipelinq', 'Verschil binnen tolerantie ({pct}%); klaar voor goedkeuring', { pct: diff.diffPercentage?.toFixed(2) }) }}
					</NcNoteCard>
				</div>
			</div>
			<p v-else class="empty-state">
				{{ t('pipelinq', 'Kassaverschil wordt berekend na het afsluiten van de shift') }}
			</p>
		</CnDetailCard>

		<!-- 5. Notes panel -->
		<CnDetailCard :title="t('pipelinq', 'Notities')">
			<NcTextArea
				v-model="notes"
				:disabled="shift.status === 'reconciled'"
				:label="t('pipelinq', 'Shiftnotities')"
				:placeholder="t('pipelinq', 'Voeg notities toe...')"
				rows="3"
				@change="saveNotes" />
		</CnDetailCard>

		<!-- Drop modal -->
		<NcModal v-if="showDropModal" @close="showDropModal = false">
			<template #default>
				<div class="drop-modal">
					<h2>{{ t('pipelinq', 'Geld verwijderen') }}</h2>
					<NcTextField
						v-model="dropForm.amount"
						type="number"
						:label="t('pipelinq', 'Bedrag (€)')"
						:placeholder="'0.00'"
						min="0.01"
						step="0.01"
						required />
					<NcSelect
						v-model="dropForm.reason"
						:options="dropReasonOptions"
						:label="t('pipelinq', 'Reden')"
						:placeholder="t('pipelinq', 'Selecteer reden...')" />
					<div class="modal-actions">
						<NcButton type="secondary" @click="showDropModal = false">
							{{ t('pipelinq', 'Annuleren') }}
						</NcButton>
						<NcButton type="primary" :disabled="busy || !dropForm.amount" @click="submitDrop">
							{{ t('pipelinq', 'Opslaan') }}
						</NcButton>
					</div>
					<NcNoteCard v-if="dropError" type="error">
						{{ dropError }}
					</NcNoteCard>
				</div>
			</template>
		</NcModal>

		<!-- Count modal -->
		<NcModal v-if="showCountModal" @close="showCountModal = false">
			<template #default>
				<div class="count-modal">
					<h2>{{ t('pipelinq', 'Kassatelling invoeren') }}</h2>
					<p class="blind-count-instruction">
						{{ t('pipelinq', 'Voer het totale contante bedrag in dat in de kassalade aanwezig is. Er wordt geen verwacht bedrag getoond (blind count).') }}
					</p>
					<NcTextField
						v-model="countForm.amount"
						type="number"
						:label="t('pipelinq', 'Geteld bedrag (€)')"
						:placeholder="'€ 0.00'"
						min="0"
						step="0.01"
						required />
					<NcTextArea
						v-model="countForm.notes"
						:label="t('pipelinq', 'Notities (optioneel)')"
						:placeholder="t('pipelinq', 'Bijv. bevat €50 vreemde valuta')"
						rows="2" />
					<div class="modal-actions">
						<NcButton type="secondary" @click="showCountModal = false">
							{{ t('pipelinq', 'Annuleren') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="busy || countForm.amount === '' || countForm.amount === null"
							@click="submitCount">
							{{ t('pipelinq', 'Shift afsluiten en bevestigen') }}
						</NcButton>
					</div>
					<NcNoteCard v-if="countError" type="error">
						{{ countError }}
					</NcNoteCard>
				</div>
			</template>
		</NcModal>

		<!-- Reject diff modal -->
		<NcModal v-if="showRejectModal" @close="showRejectModal = false">
			<template #default>
				<div class="reject-modal">
					<h2>{{ t('pipelinq', 'Kassaverschil afwijzen') }}</h2>
					<NcTextArea
						v-model="rejectReason"
						:label="t('pipelinq', 'Reden voor afwijzing')"
						:placeholder="t('pipelinq', 'Bijv. hertelling vereist; mogelijk scannerfout')"
						rows="3"
						required />
					<div class="modal-actions">
						<NcButton type="secondary" @click="showRejectModal = false">
							{{ t('pipelinq', 'Annuleren') }}
						</NcButton>
						<NcButton type="error" :disabled="busy || !rejectReason" @click="submitReject">
							{{ t('pipelinq', 'Afwijzen') }}
						</NcButton>
					</div>
				</div>
			</template>
		</NcModal>
	</CnDetailPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'CashShiftDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},
	props: {
		shiftId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			busy: false,
			shift: {},
			drops: [],
			diff: null,
			notes: '',
			showDropModal: false,
			showCountModal: false,
			showRejectModal: false,
			dropForm: { amount: '', reason: null },
			countForm: { amount: '', notes: '' },
			rejectReason: '',
			dropError: '',
			countError: '',
		}
	},
	computed: {
		/**
		 * Whether the count/close button should be shown.
		 *
		 * @return {boolean}
		 */
		canCount() {
			return this.shift.status === 'open'
		},
		/**
		 * Whether the approve-diff button should be shown.
		 * Only when shift is closed and diff is pending.
		 *
		 * @return {boolean}
		 */
		canApproveDiff() {
			return this.shift.status === 'closed' && this.diff?.status === 'pending'
		},
		/**
		 * Whether the reject-diff button should be shown.
		 *
		 * @return {boolean}
		 */
		canRejectDiff() {
			return this.shift.status === 'closed' && this.diff?.status === 'pending'
		},
		/**
		 * CSS class for the diff amount based on sign.
		 *
		 * @return {string}
		 */
		diffClass() {
			const amount = this.diff?.diffAmount ?? 0
			if (amount < 0) return 'diff-panel__value--shortage'
			if (amount > 0) return 'diff-panel__value--overage'
			return ''
		},
		/**
		 * CSS class for the tolerance indicator.
		 *
		 * @return {string}
		 */
		toleranceClass() {
			return this.diff?.withinTolerance
				? 'diff-panel__tolerance--ok'
				: 'diff-panel__tolerance--warning'
		},
		/**
		 * Props passed to the detail sidebar.
		 *
		 * @return {object}
		 */
		sidebarProps() {
			return { object: this.shift }
		},
		/**
		 * Drop reason options for the dropdown.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		dropReasonOptions() {
			return [
				{ id: 'manager-deposit', label: this.t('pipelinq', 'Manager-storting') },
				{ id: 'bank-run', label: this.t('pipelinq', 'Bank-rit') },
				{ id: 'security-removal', label: this.t('pipelinq', 'Beveiligingsafname') },
				{ id: 'other', label: this.t('pipelinq', 'Overige') },
			]
		},
	},
	mounted() {
		this.load()
	},
	methods: {
		/**
		 * Load the shift, its drops, and the associated diff.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const shiftData = await objectStore.fetchObject({
					register: 'pipelinq',
					schema: 'cashShift',
					id: this.shiftId,
				})
				this.shift = shiftData ?? {}
				this.notes = this.shift.notes ?? ''
				await this.loadDrops()
				await this.loadDiff()
			} catch (error) {
				showError(this.t('pipelinq', 'Fout bij ophalen van kassashift'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Load all cash drops for this shift.
		 *
		 * @return {Promise<void>}
		 */
		async loadDrops() {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects({
					register: 'pipelinq',
					schema: 'cashDrop',
					filters: { shift: this.shiftId },
				})
				this.drops = result?.results ?? []
			} catch {
				this.drops = []
			}
		},
		/**
		 * Load the cashDiff for this shift (if any).
		 *
		 * @return {Promise<void>}
		 */
		async loadDiff() {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects({
					register: 'pipelinq',
					schema: 'cashDiff',
					filters: { shift: this.shiftId },
				})
				const diffs = result?.results ?? []
				// Most recent diff wins (last-in-wins for recount scenarios).
				this.diff = diffs.length > 0 ? diffs[diffs.length - 1] : null
			} catch {
				this.diff = null
			}
		},
		/**
		 * Submit a new cash drop.
		 *
		 * @return {Promise<void>}
		 */
		async submitDrop() {
			this.dropError = ''
			if (!this.dropForm.amount || parseFloat(this.dropForm.amount) < 0.01) {
				this.dropError = this.t('pipelinq', 'Bedrag moet minimaal €0.01 zijn')
				return
			}

			this.busy = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos-shifts/{id}/drops', { id: this.shiftId })
				await axios.post(url, {
					amount: parseFloat(this.dropForm.amount),
					reason: this.dropForm.reason?.id ?? '',
				})
				showSuccess(this.t('pipelinq', 'Kassaafdracht geregistreerd'))
				this.showDropModal = false
				this.dropForm = { amount: '', reason: null }
				await this.loadDrops()
			} catch (error) {
				this.dropError = error?.response?.data?.error ?? this.t('pipelinq', 'Fout bij opslaan van afdracht')
			} finally {
				this.busy = false
			}
		},
		/**
		 * Submit the blind count and close the shift.
		 *
		 * @return {Promise<void>}
		 */
		async submitCount() {
			this.countError = ''
			if (this.countForm.amount === '' || this.countForm.amount === null) {
				this.countError = this.t('pipelinq', 'Geteld bedrag is verplicht')
				return
			}

			this.busy = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos-shifts/{id}/count', { id: this.shiftId })
				await axios.post(url, {
					amount: parseFloat(this.countForm.amount),
					notes: this.countForm.notes,
				})
				showSuccess(this.t('pipelinq', 'Kassatelling opgeslagen en shift gesloten'))
				this.showCountModal = false
				this.countForm = { amount: '', notes: '' }
				await this.load()
			} catch (error) {
				this.countError = error?.response?.data?.error ?? this.t('pipelinq', 'Fout bij opslaan van kassatelling')
			} finally {
				this.busy = false
			}
		},
		/**
		 * Confirm and submit diff approval.
		 *
		 * @return {Promise<void>}
		 */
		async confirmApproveDiff() {
			if (!this.diff?.id) return
			this.busy = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos-shifts/{id}/diff/approve', { id: this.diff.id })
				await axios.post(url)
				showSuccess(this.t('pipelinq', 'Kassaverschil goedgekeurd'))
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.error ?? this.t('pipelinq', 'Fout bij goedkeuren van kassaverschil'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Submit diff rejection with reason.
		 *
		 * @return {Promise<void>}
		 */
		async submitReject() {
			if (!this.diff?.id || !this.rejectReason) return
			this.busy = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos-shifts/{id}/diff/reject', { id: this.diff.id })
				await axios.post(url, { reason: this.rejectReason })
				showSuccess(this.t('pipelinq', 'Kassaverschil afgewezen — shift heropend voor hertelling'))
				this.showRejectModal = false
				this.rejectReason = ''
				await this.load()
			} catch (error) {
				showError(error?.response?.data?.error ?? this.t('pipelinq', 'Fout bij afwijzen van kassaverschil'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Save the notes field (debounced by @change).
		 *
		 * @return {Promise<void>}
		 */
		async saveNotes() {
			try {
				const objectStore = useObjectStore()
				await objectStore.saveObject({
					register: 'pipelinq',
					schema: 'cashShift',
					id: this.shiftId,
					object: { ...this.shift, notes: this.notes },
				})
			} catch {
				showError(this.t('pipelinq', 'Fout bij opslaan van notities'))
			}
		},
		/**
		 * Human-readable shift status label.
		 *
		 * @param {string} status The status value.
		 * @return {string} The translated label.
		 */
		statusLabel(status) {
			const labels = {
				open: this.t('pipelinq', 'Open'),
				closed: this.t('pipelinq', 'Gesloten'),
				reconciled: this.t('pipelinq', 'Afgestemd'),
			}
			return labels[status] ?? status
		},
		/**
		 * Human-readable diff status label.
		 *
		 * @param {string} status The diff status.
		 * @return {string} The translated label.
		 */
		diffStatusLabel(status) {
			const labels = {
				pending: this.t('pipelinq', 'In behandeling'),
				approved: this.t('pipelinq', 'Goedgekeurd'),
				rejected: this.t('pipelinq', 'Afgewezen'),
			}
			return labels[status] ?? status
		},
		/**
		 * Human-readable drop reason label.
		 *
		 * @param {string} reason The reason code.
		 * @return {string} The translated label.
		 */
		reasonLabel(reason) {
			const labels = {
				'manager-deposit': this.t('pipelinq', 'Manager-storting'),
				'bank-run': this.t('pipelinq', 'Bank-rit'),
				'security-removal': this.t('pipelinq', 'Beveiligingsafname'),
				'other': this.t('pipelinq', 'Overige'),
			}
			return labels[reason] ?? reason ?? '-'
		},
		/**
		 * Format a number as EUR currency string.
		 *
		 * @param {number|null} value The monetary value.
		 * @return {string} The formatted string.
		 */
		formatCurrency(value) {
			if (value === null || value === undefined) return '-'
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(value)
		},
		/**
		 * Format an ISO 8601 datetime for display.
		 *
		 * @param {string|null} iso The ISO 8601 string.
		 * @return {string} The formatted date.
		 */
		formatDate(iso) {
			if (!iso) return '-'
			return new Date(iso).toLocaleString('nl-NL', {
				dateStyle: 'short',
				timeStyle: 'short',
			})
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 1rem;
}

.info-field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.info-field label {
	font-weight: 600;
	font-size: 0.85rem;
	color: var(--color-text-lighter, #555);
}

.empty-state {
	color: var(--color-text-lighter, #888);
	font-style: italic;
}

.cash-shift-detail__drops {
	width: 100%;
	border-collapse: collapse;
}

.cash-shift-detail__drops th,
.cash-shift-detail__drops td {
	padding: 0.5rem 0.75rem;
	text-align: left;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.drops-panel__add {
	margin-bottom: 1rem;
}

.blind-count-instruction {
	color: var(--color-text-lighter, #666);
	margin-bottom: 1rem;
}

.modal-actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-end;
	margin-top: 1rem;
}

.diff-panel__value--shortage {
	color: var(--color-error, #e53935);
	font-weight: 600;
}

.diff-panel__value--overage {
	color: var(--color-success, #43a047);
	font-weight: 600;
}

.diff-panel__tolerance--ok {
	color: var(--color-success, #43a047);
	font-weight: 600;
}

.diff-panel__tolerance--warning {
	color: var(--color-warning, #f57c00);
	font-weight: 600;
}

.diff-panel__warning,
.diff-panel__info {
	margin-top: 1rem;
}

.drop-modal,
.count-modal,
.reject-modal {
	padding: 1.5rem;
	min-width: 320px;
}

.drop-modal h2,
.count-modal h2,
.reject-modal h2 {
	margin-bottom: 1rem;
}
</style>
