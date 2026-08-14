<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="booking-portal">
		<a class="booking-skip-link" href="#booking-form">{{
			t('pipelinq', 'Skip to booking form')
		}}</a>

		<div
			v-if="loadingService"
			class="booking-state"
			role="status"
			aria-live="polite">
			{{ t('pipelinq', 'Loading…') }}
		</div>

		<p v-else-if="loadError" class="booking-error" role="alert">
			{{ loadError }}
		</p>

		<div v-else-if="service" class="booking-layout">
			<!-- Service header -->
			<header class="booking-header">
				<h1>{{ service.name }}</h1>
				<p v-if="service.description" class="booking-description">
					{{ service.description }}
				</p>
				<dl class="booking-meta">
					<div class="booking-meta-item">
						<dt>{{ t('pipelinq', 'Duration') }}</dt>
						<dd>{{ durationLabel }}</dd>
					</div>
					<div v-if="hasPrice" class="booking-meta-item">
						<dt>{{ t('pipelinq', 'Price') }}</dt>
						<dd>{{ priceLabel }}</dd>
					</div>
				</dl>
			</header>

			<!-- Date picker -->
			<section class="booking-section" aria-labelledby="booking-date-heading">
				<h2 id="booking-date-heading">
					{{ t('pipelinq', 'Choose a date') }}
				</h2>
				<label class="booking-field" for="booking-date">
					<span class="booking-field-label">{{
						t('pipelinq', 'Date')
					}}</span>
					<input
						id="booking-date"
						v-model="selectedDate"
						type="date"
						:min="minDate"
						:max="maxDate"
						class="booking-input"
						@change="onDateChange" />
				</label>
				<p
					class="booking-hint"
					:class="{ 'booking-hint--enabled': availableDates.length }">
					{{ availableDatesHint }}
				</p>
			</section>

			<!-- Slot picker -->
			<section
				v-if="selectedDate"
				class="booking-section"
				aria-labelledby="booking-slot-heading">
				<h2 id="booking-slot-heading">
					{{ t('pipelinq', 'Choose a time') }}
				</h2>
				<div
					v-if="loadingSlots"
					class="booking-state"
					role="status"
					aria-live="polite">
					{{ t('pipelinq', 'Loading available times…') }}
				</div>
				<p v-else-if="!slots.length" class="booking-hint" role="status">
					{{
						t(
							'pipelinq',
							'No available times on this date. Please choose another date.',
						)
					}}
				</p>
				<ul v-else class="booking-slots" role="list">
					<li v-for="slot in slots" :key="slot.startAt">
						<button
							type="button"
							class="booking-slot"
							:class="{
								'booking-slot--selected':
									selectedSlot === slot.startAt,
							}"
							:aria-pressed="
								selectedSlot === slot.startAt ? 'true' : 'false'
							"
							@click="selectSlot(slot)">
							{{ formatTime(slot.startAt) }}
						</button>
					</li>
				</ul>
			</section>

			<!-- Booking form -->
			<section
				v-if="selectedSlot"
				id="booking-form"
				class="booking-section"
				aria-labelledby="booking-form-heading">
				<h2 id="booking-form-heading">
					{{ t('pipelinq', 'Your details') }}
				</h2>
				<form novalidate @submit.prevent="onSubmit">
					<label class="booking-field" for="booking-name">
						<span class="booking-field-label">
							{{ t('pipelinq', 'Name') }}
							<span aria-hidden="true">*</span>
						</span>
						<input
							id="booking-name"
							v-model.trim="form.name"
							type="text"
							autocomplete="name"
							required
							:aria-invalid="fieldErrors.name ? 'true' : 'false'"
							:aria-describedby="
								fieldErrors.name ? 'booking-name-error' : null
							"
							class="booking-input" />
						<span
							v-if="fieldErrors.name"
							id="booking-name-error"
							class="booking-field-error"
							role="alert"
							>{{ fieldErrors.name }}</span
						>
					</label>

					<label class="booking-field" for="booking-email">
						<span class="booking-field-label">
							{{ t('pipelinq', 'Email address') }}
							<span aria-hidden="true">*</span>
						</span>
						<input
							id="booking-email"
							v-model.trim="form.email"
							type="email"
							autocomplete="email"
							required
							:aria-invalid="fieldErrors.email ? 'true' : 'false'"
							:aria-describedby="
								fieldErrors.email ? 'booking-email-error' : null
							"
							class="booking-input" />
						<span
							v-if="fieldErrors.email"
							id="booking-email-error"
							class="booking-field-error"
							role="alert"
							>{{ fieldErrors.email }}</span
						>
					</label>

					<label class="booking-field" for="booking-phone">
						<span class="booking-field-label">{{
							t('pipelinq', 'Phone number')
						}}</span>
						<input
							id="booking-phone"
							v-model.trim="form.phone"
							type="tel"
							autocomplete="tel"
							class="booking-input" />
					</label>

					<label class="booking-field" for="booking-notes">
						<span class="booking-field-label">{{
							t('pipelinq', 'Notes')
						}}</span>
						<textarea
							id="booking-notes"
							v-model.trim="form.notes"
							rows="3"
							class="booking-input booking-textarea" />
					</label>

					<p
						v-if="submitError"
						class="booking-error"
						role="alert"
						aria-live="assertive">
						{{ submitError }}
					</p>

					<button
						type="submit"
						class="booking-button-primary"
						:disabled="submitting">
						{{
							submitting
								? t('pipelinq', 'Booking…')
								: t('pipelinq', 'Confirm booking')
						}}
					</button>
				</form>
			</section>
		</div>

		<p v-else class="booking-error" role="alert">
			{{ t('pipelinq', 'This service could not be found.') }}
		</p>
	</div>
</template>

<script>
import {
	fetchServiceBySlug,
	fetchAvailability,
	submitBooking,
} from '../../services/bookingPortalApi.js'

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export default {
	name: 'BookingPortal',
	data() {
		return {
			service: null,
			loadingService: true,
			loadError: '',
			selectedDate: '',
			availableDates: [],
			slots: [],
			loadingSlots: false,
			selectedSlot: null,
			form: {
				name: '',
				email: '',
				phone: '',
				notes: '',
			},
			fieldErrors: {},
			submitting: false,
			submitError: '',
		}
	},
	computed: {
		/**
		 * The service slug from the route.
		 *
		 * @return {string} The slug.
		 */
		serviceSlug() {
			return this.$route && this.$route.params
				? this.$route.params.serviceSlug
				: ''
		},
		/**
		 * Today's date as an ISO YYYY-MM-DD string (picker minimum).
		 *
		 * @return {string} The min date.
		 */
		minDate() {
			return new Date().toISOString().slice(0, 10)
		},
		/**
		 * The furthest bookable date (90 days out).
		 *
		 * @return {string} The max date.
		 */
		maxDate() {
			const d = new Date()
			d.setDate(d.getDate() + 90)
			return d.toISOString().slice(0, 10)
		},
		/**
		 * Whether the service carries a non-zero price.
		 *
		 * @return {boolean} True when priced.
		 */
		hasPrice() {
			return this.service && Number(this.service.price) > 0
		},
		/**
		 * The formatted price label.
		 *
		 * @return {string} The price.
		 */
		priceLabel() {
			const cur = (this.service && this.service.currency) || 'EUR'
			const amount = Number(this.service ? this.service.price : 0)
			try {
				return new Intl.NumberFormat(undefined, {
					style: 'currency',
					currency: cur,
				}).format(amount)
			} catch (e) {
				return amount + ' ' + cur
			}
		},
		/**
		 * The formatted duration label.
		 *
		 * @return {string} The duration.
		 */
		durationLabel() {
			const mins = Number((this.service && this.service.durationMinutes) || 0)
			return this.n('pipelinq', '%n minute', '%n minutes', mins)
		},
		/**
		 * Hint text describing available dates.
		 *
		 * @return {string} The hint.
		 */
		availableDatesHint() {
			if (!this.availableDates.length) {
				return this.t('pipelinq', 'Pick a date to see available times.')
			}
			return this.t(
				'pipelinq',
				'Dates without available times cannot be booked.',
			)
		},
	},
	watch: {
		serviceSlug() {
			this.loadService()
		},
	},
	mounted() {
		this.loadService()
	},
	methods: {
		/**
		 * Load the service by slug.
		 */
		async loadService() {
			this.loadingService = true
			this.loadError = ''
			this.service = null
			try {
				this.service = await fetchServiceBySlug(this.serviceSlug)
			} catch (e) {
				this.loadError = this.friendlyError(e)
			} finally {
				this.loadingService = false
			}
		},
		/**
		 * Handle a date change: load available slots.
		 */
		async onDateChange() {
			this.selectedSlot = null
			this.slots = []
			if (!this.selectedDate || !this.service) {
				return
			}
			this.loadingSlots = true
			try {
				this.slots = await fetchAvailability(
					this.service.id,
					this.selectedDate,
				)
				if (
					this.slots.length
					&& !this.availableDates.includes(this.selectedDate)
				) {
					this.availableDates.push(this.selectedDate)
				}
			} catch (e) {
				this.slots = []
				this.submitError = this.friendlyError(e)
			} finally {
				this.loadingSlots = false
			}
		},
		/**
		 * Select a slot.
		 *
		 * @param {object} slot The chosen slot.
		 */
		selectSlot(slot) {
			this.selectedSlot = slot.startAt
			this.submitError = ''
		},
		/**
		 * Format an ISO timestamp as a local HH:MM time.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string} The formatted time.
		 */
		formatTime(iso) {
			const d = new Date(iso)
			if (Number.isNaN(d.getTime())) {
				return iso
			}
			return d.toLocaleTimeString(undefined, {
				hour: '2-digit',
				minute: '2-digit',
			})
		},
		/**
		 * Validate the booking form.
		 *
		 * @return {boolean} True when valid.
		 */
		validate() {
			const errors = {}
			if (!this.form.name) {
				errors.name = this.t('pipelinq', 'Please enter your name.')
			}
			if (!this.form.email) {
				errors.email = this.t('pipelinq', 'Please enter your email address.')
			} else if (!EMAIL_RE.test(this.form.email)) {
				errors.email = this.t(
					'pipelinq',
					'Please enter a valid email address.',
				)
			}
			this.fieldErrors = errors
			return Object.keys(errors).length === 0
		},
		/**
		 * Submit the booking.
		 */
		async onSubmit() {
			this.submitError = ''
			if (!this.validate()) {
				return
			}
			this.submitting = true
			try {
				const result = await submitBooking({
					serviceId: this.service.id,
					startAt: this.selectedSlot,
					name: this.form.name,
					email: this.form.email,
					phone: this.form.phone || null,
					notes: this.form.notes || null,
				})
				if (result.depositRequired && result.paymentUrl) {
					window.location.assign(result.paymentUrl)
					return
				}
				const id = result.id || result.bookingId
				this.$router.push('/booking-confirmation/' + encodeURIComponent(id))
			} catch (e) {
				this.submitError = this.friendlyError(e)
			} finally {
				this.submitting = false
			}
		},
		/**
		 * Map an error to a friendly, non-technical message (never a stack trace).
		 *
		 * @param {object} e The error.
		 * @return {string} The user-facing message.
		 */
		friendlyError(e) {
			const status = e && e.response ? e.response.status : e && e.status
			if (status === 409) {
				return this.t(
					'pipelinq',
					'That time was just taken. Please choose another slot.',
				)
			}
			if (status === 404) {
				return this.t('pipelinq', 'This service could not be found.')
			}
			const apiMessage =
				e && e.response && e.response.data ? e.response.data.message : null
			return (
				apiMessage
				|| this.t('pipelinq', 'Something went wrong. Please try again.')
			)
		},
	},
}
</script>

<style scoped>
.booking-portal {
	max-width: 640px;
	margin: 0 auto;
	padding: 24px 16px;
	color: var(--color-main-text);
}

.booking-skip-link {
	position: absolute;
	left: -999px;
}

.booking-skip-link:focus {
	position: static;
	display: inline-block;
	margin-bottom: 12px;
}

.booking-header h1 {
	margin: 0 0 8px;
	font-size: 1.6em;
}

.booking-description {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
}

.booking-meta {
	display: flex;
	gap: 24px;
	margin: 0 0 8px;
}

.booking-meta-item dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.booking-meta-item dd {
	margin: 0;
}

.booking-section {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.booking-section h2 {
	font-size: 1.15em;
	margin: 0 0 12px;
}

.booking-field {
	display: block;
	margin-bottom: 16px;
}

.booking-field-label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.booking-input {
	width: 100%;
	box-sizing: border-box;
	padding: 8px 10px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.booking-input:focus {
	border-color: var(--color-primary-element);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.booking-textarea {
	resize: vertical;
}

.booking-field-error {
	display: block;
	margin-top: 4px;
	color: var(--color-error);
	font-size: 0.9em;
}

.booking-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.booking-hint--enabled {
	color: var(--color-main-text);
}

.booking-slots {
	list-style: none;
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0;
	padding: 0;
}

.booking-slot {
	min-width: 84px;
	padding: 8px 12px;
	border: 2px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.booking-slot:hover,
.booking-slot:focus {
	border-color: var(--color-primary-element);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 1px;
}

.booking-slot--selected {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	border-color: var(--color-primary-element);
}

.booking-button-primary {
	padding: 10px 18px;
	border: none;
	border-radius: var(--border-radius);
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: bold;
	cursor: pointer;
}

.booking-button-primary:disabled {
	opacity: 0.6;
	cursor: default;
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
