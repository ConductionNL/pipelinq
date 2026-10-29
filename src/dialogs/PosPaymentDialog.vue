<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PosPaymentDialog (PqKassaAfrekenen): receives the payment for a sale. The
  - total is split over the customer's loyalty points, gift cards and the
  - rest at the chosen payment method; checking out is possible once the
  - parts add up. A gift card can be added, its balance checked, or a new
  - one sold onto the sale.
  -
  - @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-a-payment-can-be-split
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Payment received')"
		:eyebrow="eyebrow"
		width="form"
		data-testid="pos-payment-dialog"
		@closing="$emit('close')">
		<div class="pos-payment">
			<div class="pos-payment__due">
				<span>{{ t('pipelinq', 'To pay, incl. VAT') }}</span>
				<strong data-testid="pos-payment-total">{{ formatEur(total) }}</strong>
			</div>

			<h3 class="pos-payment__heading">
				{{ t('pipelinq', 'Split the payment') }}
			</h3>

			<div v-if="loyalty" class="pos-payment__part" data-testid="pos-payment-points">
				<span class="pos-payment__icon"><Star :size="18" /></span>
				<div class="pos-payment__body">
					<strong>{{ t('pipelinq', 'Loyalty points') }}</strong>
					<span class="pos-payment__meta">{{ pointsMeta }}</span>
					<div class="pos-payment__points">
						<NcTextField
							:modelValue="String(points)"
							type="number"
							min="0"
							:label="t('pipelinq', 'Points to use')"
							:aria-label="t('pipelinq', 'Points to use')"
							labelOutside
							@update:modelValue="setPoints" />
						<NcButton variant="tertiary-no-background" class="pos-payment__max" @click="usePoints(maxUsablePoints)">
							{{ t('pipelinq', 'Use the maximum') }}
						</NcButton>
					</div>
				</div>
				<span class="pos-payment__amount">{{ formatEur(pointsValue) }}</span>
			</div>

			<div
				v-for="(card, index) in split.giftCards"
				:key="card.giftCardId"
				class="pos-payment__part"
				data-testid="pos-payment-gift-card">
				<span class="pos-payment__icon"><Gift :size="18" /></span>
				<div class="pos-payment__body">
					<strong>{{ t('pipelinq', 'Gift card {serial}', { serial: card.serial }) }}</strong>
					<span class="pos-payment__meta">{{ giftCardMeta(card) }}</span>
				</div>
				<span class="pos-payment__amount">{{ formatEur(card.amount) }}</span>
				<NcButton
					variant="tertiary"
					:aria-label="t('pipelinq', 'Remove gift card {serial}', { serial: card.serial })"
					@click="giftCards.splice(index, 1)">
					<template #icon>
						<Close :size="16" />
					</template>
				</NcButton>
			</div>

			<div class="pos-payment__part" data-testid="pos-payment-rest">
				<span class="pos-payment__icon"><CreditCardOutline :size="18" /></span>
				<div class="pos-payment__body">
					<strong>{{ restLabel }}</strong>
					<span class="pos-payment__meta">{{ restHint }}</span>
				</div>
				<span class="pos-payment__amount">{{ formatEur(split.rest) }}</span>
			</div>

			<div class="pos-payment__chips">
				<NcButton variant="tertiary" class="pos-payment__chip" @click="openCard('add')">
					<template #icon>
						<Plus :size="16" />
					</template>
					{{ t('pipelinq', 'Add gift card') }}
				</NcButton>
				<NcButton variant="tertiary" class="pos-payment__chip" @click="openCard('check')">
					<template #icon>
						<Magnify :size="16" />
					</template>
					{{ t('pipelinq', 'Check gift card balance') }}
				</NcButton>
			</div>

			<form v-if="cardMode" class="pos-payment__card-form" @submit.prevent="lookupCard">
				<NcTextField
					v-model="cardSerial"
					:label="t('pipelinq', 'Gift card number')"
					:aria-label="t('pipelinq', 'Gift card number')"
					labelOutside />
				<NcTextField
					v-model="cardPin"
					:label="t('pipelinq', 'PIN')"
					:aria-label="t('pipelinq', 'PIN')"
					labelOutside
					type="password" />
				<NcButton type="submit" :disabled="!cardSerial || looking">
					{{ cardMode === 'add' ? t('pipelinq', 'Add') : t('pipelinq', 'Check balance') }}
				</NcButton>
			</form>
			<p v-if="cardMessage" class="pos-payment__card-message" role="status">
				{{ cardMessage }}
			</p>

			<form v-if="selling" class="pos-payment__card-form" @submit.prevent="sellGiftCard">
				<NcTextField
					v-model="sellAmount"
					type="number"
					min="1"
					step="0.01"
					:label="t('pipelinq', 'Gift card amount')"
					:aria-label="t('pipelinq', 'Gift card amount')"
					labelOutside />
				<NcButton type="submit" :disabled="!(Number(sellAmount) > 0)">
					{{ t('pipelinq', 'Add to the sale') }}
				</NcButton>
			</form>

			<div
				role="status"
				class="pos-payment__note"
				:class="split.closes ? 'pos-payment__note--closes' : 'pos-payment__note--open'"
				data-testid="pos-payment-note">
				<Check v-if="split.closes" :size="18" />
				<AlertOutline v-else :size="18" />
				{{ closingText }}
			</div>
		</div>

		<template #actions>
			<NcButton class="pos-payment__sell" @click="selling = !selling">
				<template #icon>
					<Gift :size="16" />
				</template>
				{{ t('pipelinq', 'Sell gift card') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!split.closes || submitting"
				data-testid="payment-confirm"
				@click="confirm">
				<template #icon>
					<Check :size="16" />
				</template>
				{{ t('pipelinq', 'Check out {amount}', { amount: formatEur(total) }) }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import Gift from 'vue-material-design-icons/GiftOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import { formatDate } from '../services/localeUtils.js'
import { maxPoints, pointsAmount, splitPayment } from '../services/posSplitPayment.js'
import { formatEur } from '../services/posTotals.js'

export default {
	name: 'PosPaymentDialog',
	components: {
		AlertOutline,
		Check,
		Close,
		CnDialog,
		CreditCardOutline,
		Gift,
		Magnify,
		NcButton,
		NcTextField,
		Plus,
		Star,
	},

	props: {
		/** What the sale costs, incl. VAT. */
		total: {
			type: Number,
			required: true,
		},

		/** The line above the title: "Check out · <register> · <customer>". */
		eyebrow: {
			type: String,
			default: '',
		},

		/** The customer's name, for the points line. */
		customerName: {
			type: String,
			default: '',
		},

		/** The customer's loyalty account: `{ accountId, balance, pointValue }`, or null. */
		loyalty: {
			type: Object,
			default: null,
		},

		/** The payment method the rest is paid with. */
		restLabel: {
			type: String,
			required: true,
		},

		/** Where the rest is paid. */
		restHint: {
			type: String,
			default: '',
		},

		submitting: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm', 'sellGiftCard'],

	data() {
		return {
			points: 0,
			giftCards: [],
			cardMode: '',
			cardSerial: '',
			cardPin: '',
			cardMessage: '',
			looking: false,
			selling: false,
			sellAmount: '',
		}
	},

	computed: {
		pointValue() {
			return Number(this.loyalty?.pointValue) || 0
		},

		pointsValue() {
			return pointsAmount(this.points, this.pointValue)
		},

		maxUsablePoints() {
			return this.loyalty ? maxPoints(this.loyalty.balance, this.pointValue, this.total) : 0
		},

		split() {
			return splitPayment(this.total, { pointsValue: this.pointsValue, giftCards: this.giftCards })
		},

		/**
		 * "Sanne has 340 points · 20 points is € 1,00 · after this sale 140 points left".
		 *
		 * @return {string} The line.
		 */
		pointsMeta() {
			const perEuro = this.pointValue > 0 ? Math.round(1 / this.pointValue) : 0
			return [
				t('pipelinq', '{name} has {points} points', { name: this.customerName || t('pipelinq', 'The customer'), points: this.loyalty.balance }),
				t('pipelinq', '{points} points is {amount}', { points: perEuro, amount: formatEur(1) }),
				t('pipelinq', 'after this sale {points} points left', { points: Math.max(0, this.loyalty.balance - this.points) }),
			].join(' · ')
		},

		/**
		 * "Payment closes: € 10,00 + € 25,00 + € 61,41 is € 96,41", or what is
		 * still to pay.
		 *
		 * @return {string} The sentence.
		 */
		closingText() {
			if (this.split.closes) {
				return t('pipelinq', 'Payment closes: {parts} is {total}', {
					parts: this.split.parts.map((amount) => formatEur(amount)).join(' + '),
					total: formatEur(this.total),
				})
			}
			return t('pipelinq', 'The parts do not add up to {total} yet.', { total: formatEur(this.total) })
		},
	},

	methods: {
		formatEur,

		setPoints(value) {
			this.usePoints(Number(value) || 0)
		},

		/**
		 * Spend this many points, no more than the account and the sale allow.
		 *
		 * @param {number} points The points.
		 */
		usePoints(points) {
			this.points = Math.max(0, Math.min(Math.floor(points), this.maxUsablePoints))
		},

		giftCardMeta(card) {
			const parts = [t('pipelinq', 'Balance {amount}', { amount: formatEur(card.balance) })]
			if (card.expiryDate) {
				parts.push(t('pipelinq', 'valid until {date}', { date: formatDate(card.expiryDate, { day: 'numeric', month: 'long', year: 'numeric' }) }))
			}
			parts.push(t('pipelinq', 'balance after this sale {amount}', { amount: formatEur(card.balance - card.amount) }))
			return parts.join(' · ')
		},

		openCard(mode) {
			this.cardMode = this.cardMode === mode ? '' : mode
			this.cardMessage = ''
		},

		/**
		 * Look a gift card up by its number and PIN: add it to the payment, or
		 * only say its balance.
		 *
		 * @return {Promise<void>}
		 */
		async lookupCard() {
			this.looking = true
			this.cardMessage = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/loyalty/gift-card/validate'), { serial: this.cardSerial, pin: this.cardPin })
				if (!data?.valid) {
					this.cardMessage = t('pipelinq', 'This gift card cannot be used: {reason}', { reason: data?.reason || t('pipelinq', 'unknown') })
					return
				}
				if (this.cardMode === 'check') {
					this.cardMessage = t('pipelinq', 'Gift card {serial} has {amount}.', { serial: this.cardSerial, amount: formatEur(data.balance) })
					return
				}
				if (!this.giftCards.some((card) => card.giftCardId === data.giftCardId)) {
					this.giftCards.push({ giftCardId: data.giftCardId, serial: this.cardSerial, pin: this.cardPin, balance: Number(data.balance) || 0, amount: Number(data.balance) || 0, expiryDate: data.expiryDate || null })
				}
				this.cardMode = ''
				this.cardSerial = ''
				this.cardPin = ''
			} catch {
				this.cardMessage = t('pipelinq', 'The gift card could not be checked.')
			} finally {
				this.looking = false
			}
		},

		sellGiftCard() {
			this.$emit('sellGiftCard', { amount: Number(this.sellAmount) })
			this.selling = false
			this.sellAmount = ''
		},

		confirm() {
			this.$emit('confirm', {
				points: this.pointsValue > 0 ? this.points : 0,
				pointsValue: this.pointsValue,
				giftCards: this.split.giftCards.filter((card) => card.amount > 0),
				rest: this.split.rest,
			})
		},
	},
}
</script>

<style scoped>
.pos-payment {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: -8px;
}

.pos-payment__due {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	padding: 14px;
	border-radius: 8px;
	background: var(--color-background-dark);
}

.pos-payment__due strong {
	font-size: 26px;
	font-weight: 700;
}

.pos-payment__heading {
	margin: 4px 0 0;
	font-size: 15px;
	font-weight: 700;
}

.pos-payment__part {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 14px;
	border: 1px solid var(--color-primary-element);
	border-radius: 8px;
}

.pos-payment__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border-radius: 6px;
	background: var(--color-background-dark);
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.pos-payment__body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1;
	min-width: 0;
}

.pos-payment__meta {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pos-payment__points {
	display: flex;
	align-items: flex-end;
	gap: 12px;
	margin-top: 6px;
}

.pos-payment__points :deep(.input-field) {
	max-width: 180px;
}

.pos-payment__amount {
	font-weight: 700;
	white-space: nowrap;
}

.pos-payment__chips,
.pos-payment__card-form {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.pos-payment__card-message {
	margin: 0;
	font-size: 13px;
}

.pos-payment__note {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 14px;
	border-radius: 8px;
	font-size: 14px;
	font-weight: 600;
}

.pos-payment__note--closes {
	background: var(--color-success-light, #e7f3ea);
	color: var(--color-success-text);
}

.pos-payment__note--open {
	background: var(--color-warning-light, #fdf3e2);
	color: var(--color-warning-text);
}

.pos-payment__chip.pos-payment__chip {
	min-height: 34px;
	height: 34px;
	padding-inline: 12px;
	border: 0;
	border-radius: 17px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 13px;
}

.pos-payment__sell.pos-payment__sell {
	margin-inline-end: auto;
	padding-inline: 14px;
}
</style>
