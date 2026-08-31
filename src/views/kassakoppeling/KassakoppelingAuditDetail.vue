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
		:subtitle="t('pipelinq', 'Cash register audit log')"
		:backRoute="{ name: 'KassakoppelingAuditList' }"
		:backLabel="t('pipelinq', 'Back to audit log')"
		:loading="loading"
		:sidebar="{ enabled: false }">
		<template #actions>
			<NcButton
				v-if="entry.verified !== true"
				variant="primary"
				:disabled="busy"
				data-testid="kassakoppeling-audit-verify"
				@click="verify">
				{{ t('pipelinq', 'Verify signature') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Verification status')">
			<div class="kk-audit-detail__status">
				<span
					class="verify-pill"
					:class="[`verify-pill--${verifyClass}`]"
					data-testid="kassakoppeling-audit-verify-badge">
					{{ verifyHeadline }}
				</span>
				<p class="kk-audit-detail__status-description">
					{{ verifyDescription }}
				</p>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Summary')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Action') }}</label>
					<span
						class="action-badge"
						:class="[`action-badge--${actionClass}`]"
						>{{ actionLabel }}</span
					>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Operator') }}</label>
					<span>{{ entry.operatorId || '—' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Amount') }}</label>
					<strong>{{ formatEur(entry.amount) }}</strong>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Time') }}</label>
					<span>{{ formatTimestamp(entry.timestamp) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Register number') }}</label>
					<span>{{ entry.registerNumber || '—' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Exported on') }}</label>
					<span>{{
						entry.exportedAt
							? formatTimestamp(entry.exportedAt)
							: t('pipelinq', 'Not yet exported')
					}}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Entry details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Number of items') }}</label>
					<span>{{
						entry.itemCount !== undefined && entry.itemCount !== null
							? entry.itemCount
							: '—'
					}}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'VAT amount') }}</label>
					<span>{{ formatEur(entry.taxAmount) }}</span>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Description') }}</label>
					<span>{{ entry.description || '—' }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard
			v-if="entry.transactionUuid"
			:title="t('pipelinq', 'Linked transaction')">
			<p>
				<router-link
					data-testid="kassakoppeling-audit-transaction-link"
					:to="{
						name: 'PosTransactionDetail',
						params: { id: entry.transactionUuid },
					}">
					{{ entry.transactionUuid }}
				</router-link>
			</p>
			<p class="kk-audit-detail__hint">
				{{
					t(
						'pipelinq',
						'Click to open the linked POS transaction. When the transaction has been deleted, only the UUID reference remains visible.',
					)
				}}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Cryptographic data')">
			<div class="info-grid">
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Algorithm') }}</label>
					<span
						><code>HMAC-SHA256</code> ({{
							t('pipelinq', 'key on the server')
						}})</span
					>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Signature') }}</label>
					<div class="hash-row">
						<code data-testid="kassakoppeling-audit-signature">{{
							truncate(entry.signature)
						}}</code>
						<NcButton @click="copy(entry.signature, 'signature')">
							{{ t('pipelinq', 'Copy') }}
						</NcButton>
					</div>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Current hash') }}</label>
					<div class="hash-row">
						<code data-testid="kassakoppeling-audit-current-hash">{{
							truncate(entry.currentHash)
						}}</code>
						<NcButton @click="copy(entry.currentHash, 'currentHash')">
							{{ t('pipelinq', 'Copy') }}
						</NcButton>
					</div>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Previous hash') }}</label>
					<div class="hash-row">
						<code data-testid="kassakoppeling-audit-previous-hash">{{
							truncate(entry.previousHash)
						}}</code>
						<NcButton @click="copy(entry.previousHash, 'previousHash')">
							{{ t('pipelinq', 'Copy') }}
						</NcButton>
					</div>
				</div>
			</div>
			<p v-if="chainResult" class="kk-audit-detail__chain">
				<strong>{{ t('pipelinq', 'Chain check:') }}</strong>
				{{ chainResultText }}
			</p>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

const ACTION_LABELS = {
	sale: 'Sale',
	void: 'Void',
	refund: 'Refund',
	'no-sale': 'No sale',
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
			return t(
				'pipelinq',
				ACTION_LABELS[this.entry.action] || this.entry.action || '—',
			)
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
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
		 */
		verifyHeadline() {
			if (this.entry.verified === true) {
				return t('pipelinq', 'Cryptographically verified')
			}
			if (this.entry.verified === false) {
				return t('pipelinq', 'Signature invalid — possible tampering')
			}
			return t('pipelinq', 'Verification not yet performed')
		},

		/**
		 * Body text for the verification status card.
		 *
		 * @return {string} The body text.
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
		 */
		verifyDescription() {
			if (this.entry.verified === true) {
				return t(
					'pipelinq',
					'The HMAC-SHA256 signature and the SHA-256 chain hash match the stored values. The entry has not been modified since creation.',
				)
			}
			if (this.entry.verified === false) {
				return t(
					'pipelinq',
					'The signature or the chain hash does not match. This may indicate manual modification of the entry or a rotation of the secret key without re-signing.',
				)
			}
			return t(
				'pipelinq',
				'The entry has not been verified yet. Click "Verify signature" to recompute the HMAC and chain hash live.',
			)
		},

		/**
		 * Human readable result of the verify action.
		 *
		 * @return {string} The result text.
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
		 */
		chainResultText() {
			if (!this.chainResult) {
				return ''
			}
			const sig = this.chainResult.signatureValid
				? t('pipelinq', 'signature valid')
				: t('pipelinq', 'signature invalid')
			const hash = this.chainResult.hashValid
				? t('pipelinq', 'chain hash valid')
				: t('pipelinq', 'chain hash invalid')
			return `${sig}, ${hash}`
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Fetch the entry from the API.
		 *
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
		 */
		async load() {
			if (!this.entryId) {
				return
			}
			this.loading = true
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/kassakoppeling/audit/${this.entryId}`,
				)
				const response = await fetch(url, {
					method: 'GET',
					headers: {
						Accept: 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				if (!response.ok) {
					showError(t('pipelinq', 'Audit entry not found.'))
					this.entry = {}
					return
				}
				const data = await response.json()
				this.entry = data.entry || {}
			} catch (e) {
				showError(t('pipelinq', 'Could not load audit entry.'))
				this.entry = {}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger a server-side re-verification of the signature + chain hash.
		 *
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
		 */
		async verify() {
			if (!this.entryId) {
				return
			}
			this.busy = true
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/kassakoppeling/audit/${this.entryId}/verify`,
				)
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
					showError(t('pipelinq', 'Verification failed.'))
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
					showSuccess(t('pipelinq', 'Signature verified.'))
				} else {
					showError(
						t(
							'pipelinq',
							'Verification failed — entry may have been tampered with.',
						),
					)
				}
			} catch (e) {
				showError(t('pipelinq', 'Verification failed.'))
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
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: 'EUR',
				}).format(value)
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
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
		 */
		async copy(value, label) {
			if (!value) {
				return
			}
			try {
				await navigator.clipboard.writeText(value)
				showSuccess(t('pipelinq', '{label} copied.', { label }))
			} catch (e) {
				showError(t('pipelinq', 'Copy failed.'))
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
