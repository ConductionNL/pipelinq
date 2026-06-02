<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="t('pipelinq', 'Audit entry')"
		:subtitle="entry.reference || entry.registerNumber || ''"
		:back-route="{ name: 'KassakoppelingAudit' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading">
		<template #actions>
			<NcButton v-if="entry.verified === null || entry.verified === undefined"
				type="primary"
				:disabled="busy"
				@click="verify">
				{{ t('pipelinq', 'Verify signature') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Verification status')">
			<div class="verify-badge" :class="badgeClass">
				<span class="verify-badge__icon">{{ badgeIcon }}</span>
				<span>{{ badgeLabel }}</span>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Summary')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Action') }}</label>
					<span class="action-badge" :class="`action-badge--${entry.action}`">{{ actionLabel }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Operator') }}</label>
					<span>{{ entry.operatorId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Amount') }}</label>
					<span>{{ formatEur(entry.amount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Register') }}</label>
					<span>{{ entry.registerNumber || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Timestamp') }}</label>
					<span>{{ formatDate(entry.timestamp) }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Entry details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Tax amount') }}</label>
					<span>{{ formatEur(entry.taxAmount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Item count') }}</label>
					<span>{{ entry.itemCount ?? '-' }}</span>
				</div>
				<div v-if="entry.description" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Description') }}</label>
					<span>{{ entry.description }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard v-if="entry.transactionUuid" :title="t('pipelinq', 'Linked transaction')">
			<div class="info-field">
				<label>{{ t('pipelinq', 'Transaction') }}</label>
				<a href="#" @click.prevent="openTransaction">{{ entry.transactionUuid }}</a>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Signature details')">
			<div class="info-grid">
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Signature') }}</label>
					<code class="hash">{{ entry.signature || '-' }}</code>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Current hash') }}</label>
					<code class="hash">{{ entry.currentHash || '-' }}</code>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Previous hash') }}</label>
					<code class="hash">{{ entry.previousHash || '-' }}</code>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Verification algorithm') }}</label>
					<span>HMAC-SHA256</span>
				</div>
			</div>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'

const ACTION_LABELS = {
	sale: 'Sale',
	void: 'Void',
	refund: 'Refund',
	'no-sale': 'No-sale',
}

export default {
	name: 'AuditDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
	},
	props: {
		kassakoppelingAuditLogId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			entry: {},
			loading: false,
			busy: false,
		}
	},
	computed: {
		/**
		 * The entry id from the prop or route.
		 *
		 * @return {string|null} The id.
		 */
		entryId() {
			return this.kassakoppelingAuditLogId || this.$route.params.id || null
		},
		/**
		 * Translated action label.
		 *
		 * @return {string} The label.
		 */
		actionLabel() {
			return t('pipelinq', ACTION_LABELS[this.entry.action] || this.entry.action || '-')
		},
		/**
		 * CSS class for the verification badge.
		 *
		 * @return {string} The class.
		 */
		badgeClass() {
			if (this.entry.verified === true) {
				return 'verify-badge--ok'
			}
			if (this.entry.verified === false) {
				return 'verify-badge--bad'
			}
			return 'verify-badge--pending'
		},
		/**
		 * Icon character for the verification badge.
		 *
		 * @return {string} The icon.
		 */
		badgeIcon() {
			if (this.entry.verified === true) {
				return '✓'
			}
			if (this.entry.verified === false) {
				return '⚠'
			}
			return '?'
		},
		/**
		 * Human label for the verification badge.
		 *
		 * @return {string} The label.
		 */
		badgeLabel() {
			if (this.entry.verified === true) {
				return t('pipelinq', 'Cryptographically signed')
			}
			if (this.entry.verified === false) {
				return t('pipelinq', 'Signature invalid — possible tampering')
			}
			return t('pipelinq', 'Verification pending')
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Format an amount in cents as EUR.
		 *
		 * @param {number} cents The amount in cents.
		 * @return {string} The formatted amount.
		 */
		formatEur(cents) {
			const value = (Number(cents) || 0) / 100
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(value)
		},
		/**
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			if (!value) {
				return '-'
			}
			return new Date(value).toLocaleString('nl-NL')
		},
		/**
		 * Load the audit entry from the API.
		 */
		async load() {
			this.loading = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/${this.entryId}`),
					{
						method: 'GET',
						headers: {
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Could not load the audit entry.'))
					return
				}
				this.entry = data.entry || {}
			} catch (e) {
				showError(t('pipelinq', 'Could not load the audit entry.'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Trigger server-side verification and reload.
		 */
		async verify() {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/${this.entryId}/verify`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: '{}',
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Verification failed.'))
					return
				}
				showSuccess(data.verified ? t('pipelinq', 'Signature valid.') : t('pipelinq', 'Signature invalid.'))
				await this.load()
			} catch (e) {
				showError(t('pipelinq', 'Verification failed.'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Navigate to the linked transaction detail.
		 */
		openTransaction() {
			this.$router.push({ name: 'PosTransactionDetail', params: { id: this.entry.transactionUuid } })
		},
	},
}
</script>

<style scoped>
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

.hash {
	word-break: break-all;
	font-size: 12px;
}

.verify-badge {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	border-radius: var(--border-radius-pill, 16px);
	font-weight: bold;
}

.verify-badge__icon {
	font-size: 16px;
}

.verify-badge--ok {
	background: var(--color-success, #2d7d46);
	color: #fff;
}

.verify-badge--bad {
	background: var(--color-warning, #c28900);
	color: #fff;
}

.verify-badge--pending {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.action-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 16px);
	font-size: 12px;
	color: #fff;
}

.action-badge--sale {
	background: #2d7d46;
}

.action-badge--void {
	background: #c4291c;
}

.action-badge--refund {
	background: #c28900;
}

.action-badge--no-sale {
	background: #6c757d;
}
</style>
