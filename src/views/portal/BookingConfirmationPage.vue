<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="booking-confirmation">
		<div v-if="loading" class="booking-state" role="status" aria-live="polite">
			{{ t('pipelinq', 'Loading…') }}
		</div>

		<p v-else-if="error" class="booking-error" role="alert">
			{{ error }}
		</p>

		<div v-else-if="booking" class="booking-confirmation-card">
			<h1>{{ t('pipelinq', 'Your booking is confirmed') }}</h1>

			<p
				v-if="depositPending"
				class="booking-banner booking-banner--warning"
				role="status">
				{{ t('pipelinq', 'Awaiting payment') }}
				<span v-if="booking.paymentStatus"> — {{ paymentStatusLabel }}</span>
			</p>
			<p v-else class="booking-banner booking-banner--success" role="status">
				{{ emailNotice }}
			</p>

			<dl class="booking-summary">
				<div class="booking-summary-row">
					<dt>{{ t('pipelinq', 'Name') }}</dt>
					<dd>{{ booking.customerName || booking.name }}</dd>
				</div>
				<div class="booking-summary-row">
					<dt>{{ t('pipelinq', 'Service') }}</dt>
					<dd>{{ booking.serviceName }}</dd>
				</div>
				<div v-if="booking.resourceName" class="booking-summary-row">
					<dt>{{ t('pipelinq', 'With') }}</dt>
					<dd>{{ booking.resourceName }}</dd>
				</div>
				<div class="booking-summary-row">
					<dt>{{ t('pipelinq', 'Date and time') }}</dt>
					<dd>{{ formatDateTime(booking.startAt) }}</dd>
				</div>
				<div class="booking-summary-row">
					<dt>{{ t('pipelinq', 'Status') }}</dt>
					<dd>{{ statusLabel }}</dd>
				</div>
				<div v-if="hasPrice" class="booking-summary-row">
					<dt>{{ t('pipelinq', 'Price') }}</dt>
					<dd>{{ priceLabel }}</dd>
				</div>
			</dl>

			<div class="booking-actions">
				<a
					v-if="booking.rescheduleUrl"
					:href="booking.rescheduleUrl"
					class="booking-link"
					>{{ t('pipelinq', 'Reschedule') }}</a
				>
				<a
					v-if="booking.cancelUrl"
					:href="booking.cancelUrl"
					class="booking-link booking-link--danger"
					>{{ t('pipelinq', 'Cancel') }}</a
				>
			</div>
		</div>
	</div>
</template>

<script>
import { fetchBooking } from '../../services/bookingPortalApi.js'

export default {
	name: 'BookingConfirmationPage',
	data() {
		return {
			booking: null,
			loading: true,
			error: '',
		}
	},

	computed: {
		/**
		 * The booking id from the route.
		 *
		 * @return {string} The booking id.
		 */
		bookingId() {
			return this.$route && this.$route.params
				? this.$route.params.bookingId
				: ''
		},

		/**
		 * Whether a deposit payment is still pending.
		 *
		 * @return {boolean} True when pending.
		 */
		depositPending() {
			if (!this.booking) {
				return false
			}
			return (
				this.booking.depositRequired === true
				&& this.booking.paymentStatus !== 'paid'
				&& this.booking.status !== 'confirmed'
			)
		},

		/**
		 * The "email sent" notice with the customer email interpolated.
		 *
		 * @return {string} The notice.
		 */
		emailNotice() {
			const email = this.booking ? this.booking.email : ''
			return this.t(
				'pipelinq',
				'A confirmation email has been sent to {email}.',
				{ email },
			)
		},

		/**
		 * Whether the booking carries a price.
		 *
		 * @return {boolean} True when priced.
		 */
		hasPrice() {
			return this.booking && Number(this.booking.price) > 0
		},

		/**
		 * The formatted price.
		 *
		 * @return {string} The price label.
		 */
		priceLabel() {
			const cur = (this.booking && this.booking.currency) || 'EUR'
			const amount = Number(this.booking ? this.booking.price : 0)
			try {
				return new Intl.NumberFormat(undefined, {
					style: 'currency',
					currency: cur,
				}).format(amount)
			} catch {
				return amount + ' ' + cur
			}
		},

		/**
		 * The translated booking status label.
		 *
		 * @return {string} The status label.
		 */
		statusLabel() {
			const map = {
				pending: this.t('pipelinq', 'Pending'),
				confirmed: this.t('pipelinq', 'Confirmed'),
				cancelled: this.t('pipelinq', 'Cancelled'),
				completed: this.t('pipelinq', 'Completed'),
			}
			const status = this.booking ? this.booking.status : ''
			return map[status] || status
		},

		/**
		 * The translated payment status label.
		 *
		 * @return {string} The payment status label.
		 */
		paymentStatusLabel() {
			const map = {
				pending: this.t('pipelinq', 'Payment pending'),
				paid: this.t('pipelinq', 'Paid'),
				failed: this.t('pipelinq', 'Payment failed'),
			}
			const status = this.booking ? this.booking.paymentStatus : ''
			return map[status] || status
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the booking summary.
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.booking = await fetchBooking(this.bookingId)
			} catch (e) {
				const status = e && e.response ? e.response.status : e && e.status
				this.error =
					status === 404
						? this.t('pipelinq', 'This booking could not be found.')
						: this.t(
								'pipelinq',
								'Something went wrong. Please try again.',
							)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format an ISO timestamp as a local date and time.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string} The formatted date/time.
		 */
		formatDateTime(iso) {
			const d = new Date(iso)
			if (Number.isNaN(d.getTime())) {
				return iso
			}
			return d.toLocaleString(undefined, {
				weekday: 'long',
				year: 'numeric',
				month: 'long',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.booking-confirmation {
	max-width: 560px;
	margin: 0 auto;
	padding: 24px 16px;
	color: var(--color-main-text);
}

.booking-confirmation-card h1 {
	margin: 0 0 16px;
	font-size: 1.5em;
}

.booking-banner {
	padding: 12px 14px;
	border-radius: var(--border-radius);
	margin-bottom: 20px;
}

.booking-banner--success {
	background: var(--color-success, var(--color-background-dark));
	color: var(--color-main-text);
	border: 1px solid var(--color-success, var(--color-border));
}

.booking-banner--warning {
	background: var(--color-warning, var(--color-background-dark));
	color: var(--color-main-text);
	border: 1px solid var(--color-warning, var(--color-border));
}

.booking-summary {
	margin: 0 0 20px;
}

.booking-summary-row {
	display: flex;
	justify-content: space-between;
	gap: 16px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.booking-summary-row dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.booking-summary-row dd {
	margin: 0;
	text-align: right;
}

.booking-actions {
	display: flex;
	gap: 16px;
}

.booking-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.booking-link--danger {
	color: var(--color-error);
}

.booking-error {
	color: var(--color-error);
	padding: 8px 0;
}

.booking-state {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}
</style>
