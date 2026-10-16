<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The checkout dialog of the till (PqKassaAfrekenen): the amount due, split
  - over loyalty points, gift cards and the rest on the till's payment method.
  - It decides the split only; the till persists the sale, redeems the gift
  - cards, records the tenders and settles once `confirm` is emitted.
  -
  - @spec openspec/changes/screens-kassa-till-board/specs/pos-split-tender/spec.md
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Payment received')"
		:eyebrow="eyebrow"
		width="form"
		:noClose="submitting"
		@closing="$emit('cancel')">
		<div class="payment-checkout">
			<div class="payment-checkout__due">
				<span>{{ t('pipelinq', 'To pay, incl. VAT') }}</span>
				<strong>{{ money(due) }}</strong>
			</div>

			<h3 class="payment-checkout__heading">
				{{ t('pipelinq', 'Split payment') }}
			</h3>

			<div v-if="loyalty" class="payment-checkout__part" data-testid="payment-part-loyalty">
				<Star :size="20" class="payment-checkout__icon" />
				<div class="payment-checkout__body">
					<strong>{{ t('pipelinq', 'Loyalty points') }}</strong>
					<span class="payment-checkout__sub">
						{{ t('pipelinq', '{balance} points available · {rate} points is {one}', { balance: loyalty.balance, rate: loyalty.pointsPerEuro, one: money(1) }) }}
					</span>
					<div class="payment-checkout__points">
						<NcTextField
							:modelValue="String(points)"
							type="number"
							:label="t('pipelinq', 'Points to use')"
							@update:modelValue="setPoints" />
						<NcButton variant="tertiary" @click="usePointsMax">
							{{ t('pipelinq', 'Use the maximum') }}
						</NcButton>
					</div>
				</div>
				<strong>{{ money(partAmount('loyalty')) }}</strong>
			</div>

			<div
				v-for="card in split.parts.filter((p) => p.kind === 'giftCard')"
				:key="card.serial"
				class="payment-checkout__part"
				data-testid="payment-part-gift-card">
				<Gift :size="20" class="payment-checkout__icon" />
				<div class="payment-checkout__body">
					<strong>{{ t('pipelinq', 'Gift card {serial}', { serial: card.serial }) }}</strong>
					<span class="payment-checkout__sub">
						{{ t('pipelinq', 'Balance after this sale {amount}', { amount: money(card.balanceAfter) }) }}
					</span>
				</div>
				<strong>{{ money(card.amount) }}</strong>
				<NcButton
					variant="tertiary"
					:aria-label="t('pipelinq', 'Remove gift card')"
					@click="removeGiftCard(card.serial)">
					<template #icon>
						<Close :size="20" />
					</template>
				</NcButton>
			</div>

			<div class="payment-checkout__part" data-testid="payment-part-rest">
				<CreditCardOutline :size="20" class="payment-checkout__icon" />
				<div class="payment-checkout__body">
					<strong>{{ restLabel }}</strong>
					<span class="payment-checkout__sub">{{ t('pipelinq', 'The rest') }}</span>
				</div>
				<strong>{{ money(split.rest) }}</strong>
			</div>

			<div v-if="showCardForm" class="payment-checkout__card-form">
				<NcTextField v-model="cardSerial" :label="t('pipelinq', 'Gift card number')" />
				<NcTextField v-model="cardPin" type="password" :label="t('pipelinq', 'PIN')" />
				<div class="payment-checkout__card-actions">
					<NcButton :disabled="checking || !cardSerial || !cardPin" @click="lookupCard(false)">
						{{ t('pipelinq', 'Check balance') }}
					</NcButton>
					<NcButton variant="primary" :disabled="checking || !cardSerial || !cardPin" @click="lookupCard(true)">
						{{ t('pipelinq', 'Add') }}
					</NcButton>
				</div>
				<p v-if="cardMessage" class="payment-checkout__sub" role="status">
					{{ cardMessage }}
				</p>
			</div>
			<div v-else class="payment-checkout__more">
				<NcButton variant="secondary" @click="showCardForm = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'Add gift card') }}
				</NcButton>
			</div>

			<CnNoteCard
				v-if="split.parts.length > 0"
				type="success"
				:text="closingText" />
		</div>

		<template #actions>
			<NcButton :disabled="submitting" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="submitting || due <= 0"
				data-testid="payment-checkout-confirm"
				@click="$emit('confirm', confirmedSplit())">
				<template #icon>
					<Check :size="20" />
				</template>
				{{ t('pipelinq', 'Checkout {amount}', { amount: money(due) }) }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnNoteCard } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import Gift from 'vue-material-design-icons/Gift.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import { maxLoyaltyPoints, splitPayment } from '../services/paymentSplit.js'
import { formatEur } from '../services/posTotals.js'

export default {
	name: 'PaymentCheckoutDialog',
	components: { Check, Close, CnDialog, CnNoteCard, CreditCardOutline, Gift, NcButton, NcTextField, Plus, Star },
	props: {
		/** The amount due, incl. VAT. */
		due: { type: Number, required: true },
		/** The till and the customer, named in the eyebrow. */
		terminal: { type: String, default: '' },
		customerName: { type: String, default: '' },
		/** The till's payment method for the rest: `card` or `cash`. */
		restMethod: { type: String, default: 'card' },
		/** The customer's points, when they have a loyalty account: `{ balance, pointsPerEuro }`. */
		loyalty: { type: Object, default: null },
		/** Disables the buttons while the till completes the sale. */
		submitting: { type: Boolean, default: false },
	},

	emits: ['confirm', 'cancel'],
	data() {
		return {
			points: 0,
			giftCards: [],
			showCardForm: false,
			cardSerial: '',
			cardPin: '',
			cardMessage: '',
			checking: false,
		}
	},

	computed: {
		/**
		 * The split of the amount due over the parts chosen so far.
		 *
		 * @return {object}
		 */
		split() {
			return splitPayment(this.due, {
				loyalty: this.loyalty ? { points: this.points, pointsPerEuro: this.loyalty.pointsPerEuro } : null,
				giftCards: this.giftCards,
			})
		},

		/**
		 * The context line above the title: checkout, the till, the customer.
		 *
		 * @return {string}
		 */
		eyebrow() {
			return [t('pipelinq', 'Checkout'), this.terminal, this.customerName].filter(Boolean).join(' · ')
		},

		/**
		 * The name of the rest's payment method.
		 *
		 * @return {string}
		 */
		restLabel() {
			return this.restMethod === 'cash' ? t('pipelinq', 'Cash') : t('pipelinq', 'Card')
		},

		/**
		 * What the parts add up to ("€ 10,00 + € 25,00 + € 61,41 is € 96,41").
		 *
		 * @return {string}
		 */
		closingText() {
			const amounts = this.split.parts.map((p) => this.money(p.amount))
			if (this.split.rest > 0) {
				amounts.push(this.money(this.split.rest))
			}
			return t('pipelinq', 'Payment closes: {parts} is {total}', {
				parts: amounts.join(' + '),
				total: this.money(this.due),
			})
		},
	},

	methods: {
		/**
		 * An amount in euros.
		 *
		 * @param {number} value The amount.
		 * @return {string}
		 */
		money(value) {
			return formatEur(value)
		},

		/**
		 * The amount of the part of a kind.
		 *
		 * @param {string} kind The part kind.
		 * @return {number}
		 */
		partAmount(kind) {
			return this.split.parts.find((p) => p.kind === kind)?.amount || 0
		},

		/**
		 * Set the points to use, within what the account and the amount allow.
		 *
		 * @param {string} value The typed points.
		 */
		setPoints(value) {
			const max = maxLoyaltyPoints(this.loyalty?.balance, this.loyalty?.pointsPerEuro, this.due)
			this.points = Math.min(max, Math.max(0, Math.floor(Number(value) || 0)))
		},

		/** Use as many points as the account and the amount allow. */
		usePointsMax() {
			this.points = maxLoyaltyPoints(this.loyalty?.balance, this.loyalty?.pointsPerEuro, this.due)
		},

		/**
		 * Check a gift card, and add it to the payment when asked.
		 *
		 * @param {boolean} add Whether to add the card after checking it.
		 * @return {Promise<void>}
		 */
		async lookupCard(add) {
			this.checking = true
			this.cardMessage = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/loyalty/gift-card/validate'), {
					serial: this.cardSerial,
					pin: this.cardPin,
				})
				if (!data?.valid) {
					this.cardMessage = t('pipelinq', 'This gift card cannot be used: {reason}', { reason: data?.reason || '' })
					return
				}
				this.cardMessage = t('pipelinq', 'Balance {amount}', { amount: this.money(data.balance) })
				if (add && !this.giftCards.some((c) => c.serial === this.cardSerial)) {
					this.giftCards.push({ serial: this.cardSerial, pin: this.cardPin, giftCardId: data.giftCardId, balance: Number(data.balance) || 0 })
					this.cardSerial = ''
					this.cardPin = ''
					this.showCardForm = false
				}
			} catch {
				this.cardMessage = t('pipelinq', 'The gift card could not be checked.')
			} finally {
				this.checking = false
			}
		},

		/**
		 * Take a gift card off the payment.
		 *
		 * @param {string} serial The card number.
		 */
		removeGiftCard(serial) {
			this.giftCards = this.giftCards.filter((c) => c.serial !== serial)
		},

		/**
		 * The split as the till completes it: each gift card part with the
		 * PIN it is redeemed with.
		 *
		 * @return {object}
		 */
		confirmedSplit() {
			return {
				...this.split,
				parts: this.split.parts.map((part) => part.kind === 'giftCard'
					? { ...part, pin: this.giftCards.find((c) => c.serial === part.serial)?.pin || '' }
					: part),
			}
		},
	},
}
</script>

<style scoped>
.payment-checkout {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.payment-checkout__due {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.payment-checkout__due strong {
	font-size: 24px;
}

.payment-checkout__heading {
	margin: 4px 0 0;
	font-size: 15px;
}

.payment-checkout__part {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-primary-element);
	border-radius: var(--border-radius-large);
}

.payment-checkout__icon {
	flex: none;
	margin-top: 2px;
}

.payment-checkout__body {
	display: flex;
	flex: 1;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.payment-checkout__sub {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.payment-checkout__points,
.payment-checkout__card-actions,
.payment-checkout__more {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.payment-checkout__card-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>
