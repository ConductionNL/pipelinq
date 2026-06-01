<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<!--
  KassakoppelingAuditDetail — single audit log entry detail with cryptographic
  verification badge, field cards, transaction link and collapsible signature info.

  @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#6.1
-->
<template>
	<CnDetailPage
		:title="entryTitle"
		:subtitle="t('pipelinq', 'Kassakoppeling audit entry')"
		:back-route="{ name: 'KassakoppelingAudit' }"
		:back-label="t('pipelinq', 'Back to audit list')"
		:loading="loading">
		<template #actions>
			<NcButton
				v-if="entry.verified === null"
				type="secondary"
				:disabled="verifying"
				@click="triggerVerification">
				{{ t('pipelinq', 'Verify signature') }}
			</NcButton>
		</template>

		<!-- 1. Verification Status Badge -->
		<div class="verification-banner" :class="verificationBannerClass">
			<span class="verification-icon">{{ verificationIcon }}</span>
			<span class="verification-label">{{ verificationLabel }}</span>
		</div>

		<!-- 2. Summary Card -->
		<CnDetailCard :title="t('pipelinq', 'Transaction summary')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Action') }}</label>
					<span :class="['action-badge', 'action-badge--' + entry.action]">
						{{ t('pipelinq', entry.action || '-') }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Operator') }}</label>
					<span>{{ entry.operatorId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Amount') }}</label>
					<span>{{ formatAmount(entry.amount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Timestamp') }}</label>
					<span>{{ formatTimestamp(entry.timestamp) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Register') }}</label>
					<span>{{ entry.registerNumber || '-' }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- 3. Entry Details Card -->
		<CnDetailCard :title="t('pipelinq', 'Entry details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Operator ID') }}</label>
					<span>{{ entry.operatorId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Register number') }}</label>
					<span>{{ entry.registerNumber || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Action') }}</label>
					<span>{{ entry.action || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Amount (cents)') }}</label>
					<span>{{ entry.amount }} ({{ formatAmount(entry.amount) }})</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Tax amount (cents)') }}</label>
					<span>{{ entry.taxAmount }} ({{ formatAmount(entry.taxAmount) }})</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Item count') }}</label>
					<span>{{ entry.itemCount !== null && entry.itemCount !== undefined ? entry.itemCount : '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Timestamp') }}</label>
					<span>{{ entry.timestamp || '-' }}</span>
				</div>
				<div v-if="entry.description" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Description') }}</label>
					<span>{{ entry.description }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- 4. Transaction Link Card -->
		<CnDetailCard
			v-if="entry.transactionUuid"
			:title="t('pipelinq', 'Linked transaction')">
			<div class="info-grid">
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Transaction UUID') }}</label>
					<span class="transaction-link">
						<a
							:href="transactionUrl"
							class="link"
							target="_self">
							{{ entry.transactionUuid }}
						</a>
					</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- 5. Signature Details Card (collapsible) -->
		<div class="signature-card">
			<div
				class="signature-card__header"
				role="button"
				tabindex="0"
				:aria-expanded="signatureExpanded"
				@click="signatureExpanded = !signatureExpanded"
				@keydown.enter="signatureExpanded = !signatureExpanded">
				<span>{{ t('pipelinq', 'Signature details') }}</span>
				<span class="signature-card__toggle">{{ signatureExpanded ? '▲' : '▼' }}</span>
			</div>
			<div v-if="signatureExpanded" class="signature-card__body">
				<div class="info-grid">
					<div class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Signature (HMAC-SHA256)') }}</label>
						<div class="hash-row">
							<code class="hash-value" :title="entry.signature">{{ truncate(entry.signature) }}</code>
							<NcButton size="small" @click="copyToClipboard(entry.signature)">
								{{ t('pipelinq', 'Copy') }}
							</NcButton>
						</div>
					</div>
					<div class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Current hash (SHA-256)') }}</label>
						<div class="hash-row">
							<code class="hash-value" :title="entry.currentHash">{{ truncate(entry.currentHash) }}</code>
							<NcButton size="small" @click="copyToClipboard(entry.currentHash)">
								{{ t('pipelinq', 'Copy') }}
							</NcButton>
						</div>
					</div>
					<div class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Previous hash (SHA-256)') }}</label>
						<div class="hash-row">
							<code class="hash-value" :title="entry.previousHash">{{ truncate(entry.previousHash) }}</code>
							<NcButton size="small" @click="copyToClipboard(entry.previousHash)">
								{{ t('pipelinq', 'Copy') }}
							</NcButton>
						</div>
					</div>
					<div class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Chain status') }}</label>
						<span>
							{{ entry.previousHash === '0'
								? t('pipelinq', 'First entry — no previous link')
								: t('pipelinq', 'Linked to prior entry') }}
						</span>
					</div>
					<div class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Verification algorithm') }}</label>
						<span>HMAC-SHA256 ({{ t('pipelinq', 'secret key verification on backend') }})</span>
					</div>
					<div v-if="entry.exportedAt" class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Exported at') }}</label>
						<span>{{ formatTimestamp(entry.exportedAt) }}</span>
					</div>
				</div>
			</div>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'AuditDetail',

	components: {
		CnDetailPage,
		CnDetailCard,
		NcButton,
	},

	props: {
		/** The audit entry ID from the route parameter. */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			entry: {},
			loading: true,
			verifying: false,
			signatureExpanded: false,
		}
	},

	computed: {
		/**
		 * Page title derived from the entry action + register.
		 *
		 * @return {string}
		 */
		entryTitle() {
			if (!this.entry.action) return this.t('pipelinq', 'Audit entry')
			return `${this.entry.action.toUpperCase()} — ${this.entry.registerNumber || ''}`
		},

		/**
		 * CSS class for the verification banner.
		 *
		 * @return {string}
		 */
		verificationBannerClass() {
			if (this.entry.verified === true) return 'verification-banner--valid'
			if (this.entry.verified === false) return 'verification-banner--invalid'
			return 'verification-banner--pending'
		},

		/**
		 * Icon for the verification banner.
		 *
		 * @return {string}
		 */
		verificationIcon() {
			if (this.entry.verified === true) return '✓'
			if (this.entry.verified === false) return '⚠'
			return '?'
		},

		/**
		 * Label for the verification banner.
		 *
		 * @return {string}
		 */
		verificationLabel() {
			if (this.entry.verified === true) return this.t('pipelinq', 'Cryptographically signed — signature valid')
			if (this.entry.verified === false) return this.t('pipelinq', 'Signature Invalid — Possible Tampering')
			return this.t('pipelinq', 'Verification Pending')
		},

		/**
		 * URL to the linked transaction detail (pos-transaction-core).
		 *
		 * @return {string}
		 */
		transactionUrl() {
			if (!this.entry.transactionUuid) return '#'
			return generateUrl(`/apps/pipelinq/pos/${this.entry.transactionUuid}`)
		},
	},

	created() {
		this.fetchEntry()
	},

	methods: {
		/**
		 * Fetch the audit entry from the API.
		 */
		async fetchEntry() {
			this.loading = true
			try {
				const url = generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/${this.id}`)
				const response = await axios.get(url)
				this.entry = response.data?.entry ?? {}
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[KassakoppelingAuditDetail] fetch failed', e)
				this.entry = {}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger manual signature verification via the API.
		 */
		async triggerVerification() {
			if (this.verifying) return
			this.verifying = true
			try {
				const url = generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/${this.id}/verify`)
				const response = await axios.post(url)
				const result = response.data?.verified
				this.entry = { ...this.entry, verified: result }
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[KassakoppelingAuditDetail] verify failed', e)
			} finally {
				this.verifying = false
			}
		},

		/**
		 * Format an amount in cents to EUR display.
		 *
		 * @param {number} cents
		 * @return {string}
		 */
		formatAmount(cents) {
			if (cents === null || cents === undefined) return '-'
			const eur = Number(cents) / 100
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(eur)
		},

		/**
		 * Format an ISO 8601 timestamp for nl-NL locale display.
		 *
		 * @param {string} iso
		 * @return {string}
		 */
		formatTimestamp(iso) {
			if (!iso) return '-'
			try {
				return new Intl.DateTimeFormat('nl-NL', {
					year: 'numeric',
					month: '2-digit',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
					timeZone: 'UTC',
				}).format(new Date(iso))
			} catch {
				return iso
			}
		},

		/**
		 * Truncate a hash string for display.
		 *
		 * @param {string} hash
		 * @return {string}
		 */
		truncate(hash) {
			if (!hash) return '-'
			if (hash.length <= 16) return hash
			return `${hash.slice(0, 8)}…${hash.slice(-8)}`
		},

		/**
		 * Copy a string to the clipboard.
		 *
		 * @param {string} text
		 */
		copyToClipboard(text) {
			if (!text || !navigator.clipboard) return
			navigator.clipboard.writeText(text).catch(() => {})
		},
	},
}
</script>

<style scoped>
.verification-banner {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border-radius: 8px;
	margin-bottom: 16px;
	font-weight: 600;
	font-size: 1.05em;
}

.verification-banner--valid {
	background-color: var(--color-success-light, #edfaed);
	color: var(--color-success, #2d7d2d);
	border: 1px solid var(--color-success, #2d7d2d);
}

.verification-banner--invalid {
	background-color: var(--color-error-light, #fdecea);
	color: var(--color-error, #c0392b);
	border: 1px solid var(--color-error, #c0392b);
}

.verification-banner--pending {
	background-color: var(--color-background-dark);
	color: var(--color-text-lighter);
	border: 1px solid var(--color-border);
}

.verification-icon {
	font-size: 1.4em;
}

.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
	gap: 12px 24px;
	padding: 8px 0;
}

.info-field {
	display: flex;
	flex-direction: column;
}

.info-field--wide {
	grid-column: 1 / -1;
}

.info-field label {
	font-size: 0.8em;
	color: var(--color-text-lighter);
	margin-bottom: 2px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.action-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 500;
	text-transform: capitalize;
}

.action-badge--sale    { background-color: var(--color-success); color: var(--color-main-background); }
.action-badge--void    { background-color: var(--color-error);   color: var(--color-main-background); }
.action-badge--refund  { background-color: var(--color-warning);  color: var(--color-main-text); }
.action-badge--no-sale { background-color: var(--color-background-dark); color: var(--color-main-text); }

.signature-card {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin-top: 16px;
	overflow: hidden;
}

.signature-card__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	cursor: pointer;
	background-color: var(--color-background-hover);
	font-weight: 600;
	user-select: none;
}

.signature-card__header:hover {
	background-color: var(--color-background-dark);
}

.signature-card__toggle {
	font-size: 0.8em;
}

.signature-card__body {
	padding: 16px;
}

.hash-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.hash-value {
	font-family: var(--font-face-monospace, monospace);
	font-size: 0.85em;
	background-color: var(--color-background-dark);
	padding: 2px 6px;
	border-radius: 4px;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 280px;
	cursor: help;
}

.link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
