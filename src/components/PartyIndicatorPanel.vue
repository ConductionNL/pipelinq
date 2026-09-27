<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  PartyIndicatorPanel: the warnings on a client or contact (pipelinq#2036).

  Shows every indicator in force on the party, loudest first, so the next
  colleague reads an aggression registration or a death before they make
  contact. An indicator that requires it can be acknowledged, which records
  the handler and the time. A handler can add a warning from the declared
  vocabulary with a start day, an optional end day and a source.

  Props:
    partyId (string, required): the client or contact uuid.

  @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
-->
<template>
	<CnDetailCard :title="t('pipelinq', 'Warnings')">
		<div v-if="loading" class="party-indicators__state">
			<NcLoadingIcon :size="24" />
		</div>
		<p
			v-else-if="error"
			class="party-indicators__state party-indicators__state--error">
			{{ error }}
		</p>
		<p v-else-if="!indicators.length" class="party-indicators__state">
			{{ t('pipelinq', 'No warnings on this record.') }}
		</p>
		<ul v-else class="party-indicators__list" data-testid="party-indicators">
			<li
				v-for="indicator in indicators"
				:key="indicator.id || indicator.code"
				class="party-indicators__item"
				:class="`party-indicators__item--${indicator.severity}`"
				role="status">
				<div class="party-indicators__head">
					<strong>{{ indicator.label }}</strong>
					<span class="party-indicators__severity">{{
						severityLabel(indicator.severity)
					}}</span>
				</div>
				<p class="party-indicators__meta">
					{{ periodText(indicator) }}
					<template v-if="indicator.source">
						·
						{{
							t('pipelinq', 'Source: {source}', {
								source: indicator.source,
							})
						}}
					</template>
				</p>
				<NcButton
					v-if="needsAcknowledgement(indicator)"
					variant="primary"
					:disabled="busyId === indicator.id"
					@click="acknowledge(indicator)">
					{{ t('pipelinq', 'I have seen this') }}
				</NcButton>
				<p
					v-else-if="indicator.acknowledgedAt"
					class="party-indicators__meta">
					{{
						t('pipelinq', 'Seen by {user} on {date}', {
							user: indicator.acknowledgedBy,
							date: formatDay(indicator.acknowledgedAt),
						})
					}}
				</p>
			</li>
		</ul>

		<NcButton v-if="!adding" @click="openForm">
			{{ t('pipelinq', 'Add a warning') }}
		</NcButton>
		<form v-else class="party-indicators__form" @submit.prevent="save">
			<NcSelect
				v-model="form.indicator"
				:options="vocabulary"
				label="label"
				:reduce="(option) => option.code"
				:inputLabel="t('pipelinq', 'Warning')"
				:clearable="false" />
			<div class="party-indicators__field">
				<label for="party-indicator-from">{{
					t('pipelinq', 'Valid from')
				}}</label>
				<input
					id="party-indicator-from"
					v-model="form.validFrom"
					type="date"
					required />
			</div>
			<div class="party-indicators__field">
				<label for="party-indicator-until">{{
					t('pipelinq', 'Valid until (optional)')
				}}</label>
				<input
					id="party-indicator-until"
					v-model="form.validUntil"
					type="date" />
			</div>
			<NcTextField
				v-model="form.source"
				:label="t('pipelinq', 'Source (optional)')" />
			<div class="party-indicators__actions">
				<NcButton
					type="submit"
					variant="primary"
					:disabled="saving || !form.indicator || !form.validFrom">
					{{ t('pipelinq', 'Save warning') }}
				</NcButton>
				<NcButton @click="adding = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</div>
		</form>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	acknowledgeIndicator,
	addIndicatorValue,
	fetchIndicatorVocabulary,
	fetchPartyIndicators,
	needsAcknowledgement,
} from '../services/partyIndicators.js'

export default {
	name: 'PartyIndicatorPanel',
	components: { CnDetailCard, NcButton, NcLoadingIcon, NcSelect, NcTextField },
	props: {
		partyId: { type: String, required: true },
	},

	data() {
		return {
			loading: false,
			error: '',
			indicators: [],
			busyId: '',
			adding: false,
			saving: false,
			vocabulary: [],
			form: this.emptyForm(),
		}
	},

	watch: {
		partyId: {
			immediate: true,
			handler(value) {
				if (value) {
					this.load()
				}
			},
		},
	},

	methods: {
		needsAcknowledgement,

		/**
		 * @return {object} A blank add-warning form starting today.
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md
		 */
		emptyForm() {
			return {
				indicator: null,
				validFrom: new Date().toISOString().slice(0, 10),
				validUntil: '',
				source: '',
			}
		},

		/**
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.indicators = await fetchPartyIndicators(this.partyId)
			} catch {
				this.indicators = []
				this.error = t('pipelinq', 'Warnings could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} indicator The indicator to acknowledge.
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
		 */
		async acknowledge(indicator) {
			this.busyId = indicator.id
			try {
				await acknowledgeIndicator(indicator.id)
				await this.load()
			} catch {
				showError(t('pipelinq', 'The acknowledgement could not be saved.'))
			} finally {
				this.busyId = ''
			}
		},

		/**
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md
		 */
		async openForm() {
			this.form = this.emptyForm()
			this.adding = true
			try {
				this.vocabulary = await fetchIndicatorVocabulary()
			} catch {
				this.vocabulary = []
				showError(t('pipelinq', 'The list of warnings could not be loaded.'))
			}
		},

		/**
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/party-fields-and-indicators/spec.md
		 */
		async save() {
			this.saving = true
			try {
				await addIndicatorValue(this.partyId, this.form)
				showSuccess(t('pipelinq', 'Warning saved'))
				this.adding = false
				await this.load()
			} catch {
				showError(t('pipelinq', 'The warning could not be saved.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {string} severity info, warning or critical.
		 * @return {string} The label.
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
		 */
		severityLabel(severity) {
			if (severity === 'critical') {
				return t('pipelinq', 'Critical')
			}
			if (severity === 'info') {
				return t('pipelinq', 'Information')
			}
			return t('pipelinq', 'Warning')
		},

		/**
		 * @param {object} indicator The indicator.
		 * @return {string} When it applies.
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
		 */
		periodText(indicator) {
			if (indicator.validUntil) {
				return t('pipelinq', 'From {from} until {until}', {
					from: this.formatDay(indicator.validFrom),
					until: this.formatDay(indicator.validUntil),
				})
			}
			return t('pipelinq', 'Since {from}', {
				from: this.formatDay(indicator.validFrom),
			})
		},

		/**
		 * @param {string} value An ISO date or date-time.
		 * @return {string} The day, localised.
		 * @spec openspec/changes/typed-fields-and-indicators-on-a-party/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
		 */
		formatDay(value) {
			if (!value) {
				return ''
			}
			const parsed = new Date(value)
			return Number.isNaN(parsed.getTime())
				? String(value)
				: parsed.toLocaleDateString()
		},
	},
}
</script>

<style scoped>
.party-indicators__state {
	padding: 8px 0;
	color: var(--color-text-maxcontrast);
}

.party-indicators__state--error {
	color: var(--color-error-text);
}

.party-indicators__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0 0 12px;
	padding: 0;
	list-style: none;
}

.party-indicators__item {
	padding: 8px 12px;
	border-inline-start: 4px solid var(--color-border-dark);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.party-indicators__item--critical {
	border-inline-start-color: var(--color-error);
}

.party-indicators__item--warning {
	border-inline-start-color: var(--color-warning);
}

.party-indicators__item--info {
	border-inline-start-color: var(--color-info);
}

.party-indicators__head {
	display: flex;
	gap: 8px;
	align-items: baseline;
}

.party-indicators__severity,
.party-indicators__meta {
	color: var(--color-text-maxcontrast);
}

.party-indicators__meta {
	margin: 4px 0;
}

.party-indicators__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 480px;
}

.party-indicators__field {
	display: flex;
	flex-direction: column;
}

.party-indicators__actions {
	display: flex;
	gap: 8px;
}
</style>
