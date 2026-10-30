<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The deal value in the client's currency, the converted value and the rate
  - line (board PqLead). The server resolves the rate; this only shows it.
-->
<template>
	<div class="lead-exchange-rate" data-testid="lead-exchange-rate">
		<NcLoadingIcon v-if="loading" :size="24" />

		<p v-else-if="!foreign" class="lead-exchange-rate__hint">
			{{
				t('pipelinq', 'This deal is in {currency}, so nothing is converted.', {
					currency: currencyLabel,
				})
			}}
		</p>

		<NcNoteCard v-else-if="error" type="error">
			{{ t('pipelinq', 'Could not load the exchange rate.') }}
		</NcNoteCard>

		<div v-else-if="conversion" class="lead-exchange-rate__facts">
			<div class="lead-exchange-rate__fact">
				<span class="lead-exchange-rate__label">{{
					t('pipelinq', 'Deal value in the client\'s currency')
				}}</span>
				<strong>{{ formatCurrency(value, code) }}</strong>
			</div>
			<div class="lead-exchange-rate__fact">
				<span class="lead-exchange-rate__label">{{ t('pipelinq', 'Converted') }}</span>
				<strong>{{
					formatCurrency(conversion.amount, conversion.reportingCurrency)
				}}</strong>
			</div>
			<div class="lead-exchange-rate__fact">
				<span class="lead-exchange-rate__label">{{ t('pipelinq', 'Rate') }}</span>
				<span>{{ line || sourceLabel }}</span>
				<span v-if="line" class="lead-exchange-rate__source">{{ sourceLabel }}</span>
				<span
					v-if="conversion.stale"
					class="lead-exchange-rate__stale"
					data-testid="lead-exchange-rate-stale">
					{{
						t('pipelinq', 'Older than {days} days', {
							days: conversion.maxRateAgeDays,
						})
					}}
				</span>
			</div>
			<NcButton :disabled="loading" @click="load">
				{{ t('pipelinq', 'Refresh rate') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	normaliseCurrency,
	rateLine,
	rateSourceLabel,
} from '../../services/leadCurrency.js'
import { formatCurrency } from '../../services/localeUtils.js'
import { reportingCurrency } from '../../services/reportingCurrency.js'

export default {
	name: 'LeadExchangeRate',
	components: { NcButton, NcLoadingIcon, NcNoteCard },

	props: {
		/** The deal value in the deal's own currency. */
		value: { type: [Number, String], default: 0 },
		/** The deal's ISO 4217 currency; empty means the reporting currency. */
		currency: { type: String, default: '' },
	},

	data() {
		return {
			loading: false,
			error: false,
			conversion: null,
		}
	},

	computed: {
		code() {
			return normaliseCurrency(this.currency)
		},

		currencyLabel() {
			return this.code || reportingCurrency()
		},

		foreign() {
			return this.code !== '' && this.code !== reportingCurrency()
		},

		line() {
			return rateLine(this.conversion)
		},

		sourceLabel() {
			return rateSourceLabel(this.conversion)
		},
	},

	watch: {
		value: 'load',
		currency: 'load',
	},

	mounted() {
		this.load()
	},

	methods: {
		formatCurrency,

		/**
		 * Ask the server to convert the deal value at the resolved rate.
		 *
		 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
		 */
		async load() {
			if (!this.foreign) {
				this.conversion = null
				return
			}
			this.loading = true
			this.error = false
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/exchange-rates/convert'),
					{ params: { currency: this.code, amount: Number(this.value) || 0 } },
				)
				this.conversion = data
			} catch {
				this.error = true
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.lead-exchange-rate__facts {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 24px;
}

.lead-exchange-rate__fact {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.lead-exchange-rate__label,
.lead-exchange-rate__source,
.lead-exchange-rate__hint {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.lead-exchange-rate__stale {
	color: var(--color-warning-text);
	font-weight: 600;
	font-size: 13px;
}
</style>
