<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Kassakoppeling Audit Detail — read-only view of a single audit entry.
  - The schema is append-only by design — no PUT/PATCH exists — so this page
  - exposes ONLY the manual verify action (recomputes the HMAC + chain hash
  - and updates the `verified` flag). When a linked transactionUuid is
  - present, a clickable link is rendered into pos-transaction-core.
  -
  - @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
  -->
<template>
	<CnDetailPage
		:title="title"
		:subtitle="t('pipelinq', 'Kassakoppeling audit log')"
		:back-route="{ name: 'KassakoppelingAuditList' }"
		:back-label="t('pipelinq', 'Terug naar audit log')"
		:loading="loading"
		:sidebar="{ enabled: false }">
		<template #actions>
			<NcButton
				v-if="entry.verified !== true"
				type="primary"
				:disabled="busy"
				data-testid="kassakoppeling-audit-verify"
				@click="verify">
				{{ t('pipelinq', 'Handtekening verifiëren') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Verificatiestatus')">
			<div class="kk-audit-detail__status">
				<span
					:class="['verify-pill', `verify-pill--${verifyClass}`]"
					data-testid="kassakoppeling-audit-verify-badge">
					{{ verifyHeadline }}
				</span>
				<p class="kk-audit-detail__status-description">
					{{ verifyDescription }}
				</p>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Samenvatting')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Actie') }}</label>
					<span :class="['action-badge', `action-badge--${actionClass}`]">{{ actionLabel }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Operator') }}</label>
					<span>{{ entry.operatorId || '—' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Bedrag') }}</label>
					<strong>{{ formatEur(entry.amount) }}</strong>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Tijdstip') }}</label>
					<span>{{ formatTimestamp(entry.timestamp) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Kassanummer') }}</label>
					<span>{{ entry.registerNumber || '—' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Exporteerd op') }}</label>
					<span>{{ entry.exportedAt ? formatTimestamp(entry.exportedAt) : t('pipelinq', 'Nog niet geëxporteerd') }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Entry details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Aantal items') }}</label>
					<span>{{ entry.itemCount !== undefined && entry.itemCount !== null ? entry.itemCount : '—' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'BTW bedrag') }}</label>
					<span>{{ formatEur(entry.taxAmount) }}</span>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Omschrijving') }}</label>
					<span>{{ entry.description || '—' }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard v-if="entry.transactionUuid" :title="t('pipelinq', 'Gekoppelde transactie')">
			<p>
				<router-link
					data-testid="kassakoppeling-audit-transaction-link"
					:to="{ name: 'PosTransactionDetail', params: { id: entry.transactionUuid } }">
					{{ entry.transactionUuid }}
				</router-link>
			</p>
			<p class="kk-audit-detail__hint">
				{{ t('pipelinq', 'Klik om de gekoppelde POS-transactie te openen. Wanneer de transactie verwijderd is, blijft alleen de UUID-verwijzing zichtbaar.') }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Cryptografische gegevens')">
			<div class="info-grid">
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Algoritme') }}</label>
					<span><code>HMAC-SHA256</code> ({{ t('pipelinq', 'sleutel op de server') }})</span>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Handtekening') }}</label>
					<div class="hash-row">
						<code data-testid="kassakoppeling-audit-signature">{{ truncate(entry.signature) }}</code>
						<NcButton @click="copy(entry.signature, 'signature')">
							{{ t('pipelinq', 'Kopiëren') }}
						</NcButton>
					</div>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Huidige hash') }}</label>
					<div class="hash-row">
						<code data-testid="kassakoppeling-audit-current-hash">{{ truncate(entry.currentHash) }}</code>
						<NcButton @click="copy(entry.currentHash, 'currentHash')">
							{{ t('pipelinq', 'Kopiëren') }}
						</NcButton>
					</div>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Vorige hash') }}</label>
					<div class="hash-row">
						<code data-testid="kassakoppeling-audit-previous-hash">{{ truncate(entry.previousHash) }}</code>
						<NcButton @click="copy(entry.previousHash, 'previousHash')">
							{{ t('pipelinq', 'Kopiëren') }}
						</NcButton>
					</div>
				</div>
			</div>
			<p v-if="chainResult" class="kk-audit-detail__chain">
				<strong>{{ t('pipelinq', 'Keten controle:') }}</strong>
				{{ chainResultText }}
			</p>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'

const ACTION_LABELS = {
	sale: 'Verkoop',
	void: 'Annulering',
	refund: 'Retour',
	'no-sale': 'Geen verkoop',
}

const ACTION_CLASSES = {
	sale: 'sale',
	void: 'void',
	refund: 'refund',
	'no-sale': 'no-sale',
}

export default {
	name: 'KassakoppelingAuditDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
	},
	props: {
		auditEntryId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			entry: {},
			loading: false,
			busy: false,
			chainResult: null,
		}
	},
	computed: {
		/**
		 * Resolve the audit entry id from props or route.
		 *
		 * @return {string|null} The id.
		 */
		entryId() {
			return this.auditEntryId || this.$route.params.id || null
		},
		/**
		 * Title shown in the detail header.
		 *
		 * @return {string} The title.
		 */
		title() {
			if (!this.entry || !this.entry.action) {
				return t('pipelinq', 'Audit entry')
			}
			return `${this.actionLabel} — ${this.entry.registerNumber || '?'}`
		},
		/**
		 * Translated action label.
		 *
		 * @return {string} The label.
		 */
		actionLabel() {
			return t('pipelinq', ACTION_LABELS[this.entry.action] || this.entry.action || '—')
		},
		/**
		 * Action CSS class suffix.
		 *
		 * @return {string} The suffix.
		 */
		actionClass() {
			return ACTION_CLASSES[this.entry.action] || 'unknown'
		},
		/**
		 * Verification badge CSS suffix.
		 *
		 * @return {string} The suffix.
		 */
		verifyClass() {
			if (this.entry.verified === true) {
				return 'ok'
			}
			if (this.entry.verified === false) {
				return 'fail'
			}
			return 'pending'
		},
		/**
		 * Headline for the verification pill.
		 *
		 * @return {string} The headline.
		 */
		verifyHeadline() {
			if (this.entry.verified === true) {
				return t('pipelinq', 'Cryptografisch geverifieerd')
			}
			if (this.entry.verified === false) {
				return t('pipelinq', 'Handtekening ongeldig — mogelijke manipulatie')
			}
			return t('pipelinq', 'Verificatie nog niet uitgevoerd')
		},
		/**
		 * Body text for the verification status card.
		 *
		 * @return {string} The body text.
		 */
		verifyDescription() {
			if (this.entry.verified === true) {
				return t('pipelinq', 'De HMAC-SHA256 handtekening en de SHA-256 keten-hash komen overeen met de opgeslagen waarden. De entry is sinds creatie niet gewijzigd.')
			}
			if (this.entry.verified === false) {
				return t('pipelinq', 'De handtekening of de keten-hash komt niet overeen. Dit kan duiden op handmatige wijziging van de entry of een rotatie van de geheime sleutel zonder hertekenen.')
			}
			return t('pipelinq', 'De entry is nog niet geverifieerd. Klik op "Handtekening verifiëren" om de HMAC en keten-hash live opnieuw te berekenen.')
		},
		/**
		 * Human readable result of the verify action.
		 *
		 * @return {string} The result text.
		 */
		chainResultText() {
			if (!this.chainResult) {
				return ''
			}
			const sig = this.chainResult.signatureValid ? t('pipelinq', 'handtekening geldig') : t('pipelinq', 'handtekening ongeldig')
			const hash = this.chainResult.hashValid ? t('pipelinq', 'keten-hash geldig') : t('pipelinq', 'keten-hash ongeldig')
			return `${sig}, ${hash}`
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Fetch the entry from the API.
		 */
		async load() {
			if (!this.entryId) {
				return
			}
			this.loading = true
			try {
				const url = generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/${this.entryId}`)
				const response = await fetch(url, {
					method: 'GET',
					headers: { Accept: 'application/json', requesttoken: OC.requestToken },
				})
				if (!response.ok) {
					showError(t('pipelinq', 'Audit entry niet gevonden.'))
					this.entry = {}
					return
				}
				const data = await response.json()
				this.entry = data.entry || {}
			} catch (e) {
				showError(t('pipelinq', 'Audit entry kon niet worden geladen.'))
				this.entry = {}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Trigger a server-side re-verification of the signature + chain hash.
		 */
		async verify() {
			if (!this.entryId) {
				return
			}
			this.busy = true
			try {
				const url = generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/${this.entryId}/verify`)
				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						Accept: 'application/json',
						requesttoken: OC.requestToken,
					},
					body: '{}',
				})
				if (!response.ok) {
					showError(t('pipelinq', 'Verificatie mislukt.'))
					return
				}
				const data = await response.json()
				this.chainResult = {
					verified: data.verified,
					signatureValid: data.signatureValid,
					hashValid: data.hashValid,
				}
				if (data.entry) {
					this.entry = data.entry
				}
				if (data.verified === true) {
					showSuccess(t('pipelinq', 'Handtekening geverifieerd.'))
				} else {
					showError(t('pipelinq', 'Verificatie mislukt — entry is mogelijk gemanipuleerd.'))
				}
			} catch (e) {
				showError(t('pipelinq', 'Verificatie mislukt.'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Format an ISO timestamp using the nl-NL locale.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted value.
		 */
		formatTimestamp(value) {
			if (!value) {
				return '—'
			}
			try {
				return new Date(value).toLocaleString('nl-NL', {
					year: 'numeric',
					month: 'short',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				})
			} catch (e) {
				return value
			}
		},
		/**
		 * Format an integer cent value as a localised EUR string.
		 *
		 * @param {number|string} cents The amount in cents.
		 * @return {string} The formatted EUR value.
		 */
		formatEur(cents) {
			const value = Number.isFinite(Number(cents)) ? Number(cents) / 100 : 0
			try {
				return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(value)
			} catch (e) {
				return `€ ${value.toFixed(2)}`
			}
		},
		/**
		 * Truncate a long hex digest for display.
		 *
		 * @param {string} value The hex digest.
		 * @return {string} The truncated display value.
		 */
		truncate(value) {
			if (!value || typeof value !== 'string') {
				return '—'
			}
			if (value.length <= 24) {
				return value
			}
			return `${value.slice(0, 12)}…${value.slice(-8)}`
		},
		/**
		 * Copy a value to the clipboard and show feedback.
		 *
		 * @param {string} value The full value.
		 * @param {string} label A label for the toast.
		 */
		async copy(value, label) {
			if (!value) {
				return
			}
			try {
				await navigator.clipboard.writeText(value)
				showSuccess(t('pipelinq', '{label} gekopieerd.', { label }))
			} catch (e) {
				showError(t('pipelinq', 'Kopiëren mislukt.'))
			}
		},
	},
}
</script>

<style scoped>
.kk-audit-detail__status {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.verify-pill {
	align-self: flex-start;
	padding: 6px 12px;
	border-radius: 999px;
	font-weight: 600;
}

.verify-pill--ok {
	background: var(--color-success);
	color: var(--color-success-text);
}

.verify-pill--fail {
	background: var(--color-error);
	color: var(--color-error-text);
}

.verify-pill--pending {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.kk-audit-detail__status-description {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.kk-audit-detail__hint {
	margin-top: 8px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.kk-audit-detail__chain {
	margin-top: 12px;
	font-size: 13px;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field--wide {
	grid-column: 1 / -1;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.action-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 500;
}

.action-badge--sale {
	background: var(--color-success);
	color: var(--color-success-text);
}

.action-badge--void {
	background: var(--color-error);
	color: var(--color-error-text);
}

.action-badge--refund {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.action-badge--no-sale,
.action-badge--unknown {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.hash-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.hash-row code {
	font-family: var(--font-family-monospace, monospace);
	font-size: 13px;
	word-break: break-all;
}
</style>
