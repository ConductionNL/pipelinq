<template>
	<div class="contactmoment-form">
		<div class="contactmoment-form__header">
			<h2>{{ t('pipelinq', 'Register Contact Moment') }}</h2>
		</div>

		<div class="contactmoment-form__body">
			<!-- Channel Selection -->
			<div class="form-row">
				<label>{{ t('pipelinq', 'Channel') }} *</label>
				<div class="channel-selector">
					<button
						v-for="ch in channels"
						:key="ch.value"
						class="channel-button"
						:class="{ 'channel-button--active': form.channel === ch.value }"
						@click="form.channel = ch.value">
						<span class="channel-icon">{{ ch.icon }}</span>
						{{ ch.label }}
					</button>
				</div>
			</div>

			<!-- Call Timer (phone channel only) -->
			<div v-if="form.channel === 'telefoon'" class="form-row">
				<label>{{ t('pipelinq', 'Call Duration') }}</label>
				<CallTimer
					ref="timer"
					@stopped="onTimerStopped"
					@tick="onTimerTick" />
			</div>

			<!-- Core Fields -->
			<div class="form-row">
				<NcTextField
					:value.sync="form.subject"
					:label="t('pipelinq', 'Subject') + ' *'"
					:required="true" />
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="clientSearch"
					:label="t('pipelinq', 'Client (search by name)')" />
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="form.summary"
					:label="t('pipelinq', 'Notes / summary')"
					:multiline="true" />
			</div>

			<div class="form-row form-row--split">
				<div class="form-col">
					<label>{{ t('pipelinq', 'Result') }}</label>
					<select v-model="form.outcome" class="form-select">
						<option value="">
							{{ t('pipelinq', 'Select result...') }}
						</option>
						<option value="afgehandeld">
							{{ t('pipelinq', 'Resolved') }}
						</option>
						<option value="doorverbonden">
							{{ t('pipelinq', 'Forwarded') }}
						</option>
						<option value="terugbelverzoek">
							{{ t('pipelinq', 'Callback requested') }}
						</option>
						<option value="vervolgactie">
							{{ t('pipelinq', 'Follow-up action') }}
						</option>
					</select>
				</div>
				<div class="form-col">
					<label>{{ t('pipelinq', 'Initiator') }}</label>
					<select v-model="channelMeta.initiatiefnemer" class="form-select">
						<option value="klant">
							{{ t('pipelinq', 'Client') }}
						</option>
						<option value="medewerker">
							{{ t('pipelinq', 'Agent') }}
						</option>
					</select>
				</div>
			</div>

			<!-- Channel-Specific Fields -->
			<template v-if="form.channel === 'telefoon'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<label>{{ t('pipelinq', 'Direction') }}</label>
						<select v-model="channelMeta.richting" class="form-select">
							<option value="inkomend">
								{{ t('pipelinq', 'Incoming') }}
							</option>
							<option value="uitgaand">
								{{ t('pipelinq', 'Outgoing') }}
							</option>
						</select>
					</div>
					<div class="form-col">
						<NcTextField
							:value.sync="channelMeta.gespreksduur"
							:label="t('pipelinq', 'Duration (auto-filled from timer)')"
							:disabled="true" />
					</div>
				</div>
			</template>

			<template v-if="form.channel === 'email'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.afzender" :label="t('pipelinq', 'Sender email')" />
					</div>
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.threadId" :label="t('pipelinq', 'Thread ID')" />
					</div>
				</div>
			</template>

			<template v-if="form.channel === 'balie'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.locatie" :label="t('pipelinq', 'Location')" />
					</div>
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.volgnummer" :label="t('pipelinq', 'Queue number')" />
					</div>
				</div>
			</template>

			<template v-if="form.channel === 'chat'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<label>{{ t('pipelinq', 'Platform') }}</label>
						<select v-model="channelMeta.platform" class="form-select">
							<option value="website">
								{{ t('pipelinq', 'Website') }}
							</option>
							<option value="whatsapp">
								WhatsApp
							</option>
							<option value="nextcloud_talk">
								Nextcloud Talk
							</option>
						</select>
					</div>
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.transcriptLink" :label="t('pipelinq', 'Transcript link')" />
					</div>
				</div>
			</template>

			<template v-if="form.channel === 'social'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<label>{{ t('pipelinq', 'Platform') }}</label>
						<select v-model="channelMeta.platform" class="form-select">
							<option value="twitter">
								Twitter/X
							</option>
							<option value="facebook">
								Facebook
							</option>
							<option value="instagram">
								Instagram
							</option>
						</select>
					</div>
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.berichtUrl" :label="t('pipelinq', 'Message URL')" />
					</div>
				</div>
			</template>

			<template v-if="form.channel === 'brief'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<label>{{ t('pipelinq', 'Direction') }}</label>
						<select v-model="channelMeta.richting" class="form-select">
							<option value="inkomend">
								{{ t('pipelinq', 'Incoming') }}
							</option>
							<option value="uitgaand">
								{{ t('pipelinq', 'Outgoing') }}
							</option>
						</select>
					</div>
					<div class="form-col">
						<NcTextField :value.sync="channelMeta.kenmerk" :label="t('pipelinq', 'Reference number')" />
					</div>
				</div>
			</template>

			<div v-if="errorMessage" class="form-error">
				{{ errorMessage }}
			</div>

			<div class="form-row form-row--actions">
				<NcButton type="tertiary" @click="$router.back()">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !isValid"
					@click="save">
					{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Register') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import CallTimer from '../../components/CallTimer.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactmomentForm',
	components: { NcButton, NcTextField, CallTimer },
	data() {
		return {
			form: {
				channel: 'telefoon',
				subject: '',
				summary: '',
				outcome: '',
				client: null,
			},
			channelMeta: {
				richting: 'inkomend',
				gespreksduur: '',
				afzender: '',
				threadId: '',
				locatie: '',
				volgnummer: '',
				platform: 'website',
				transcriptLink: '',
				berichtUrl: '',
				kenmerk: '',
				initiatiefnemer: 'klant',
			},
			clientSearch: '',
			saving: false,
			errorMessage: '',
			channels: [
				{ value: 'telefoon', label: t('pipelinq', 'Phone'), icon: '\u260E' },
				{ value: 'email', label: t('pipelinq', 'Email'), icon: '\u2709' },
				{ value: 'balie', label: t('pipelinq', 'Counter'), icon: '\u2302' },
				{ value: 'chat', label: t('pipelinq', 'Chat'), icon: '\u2328' },
				{ value: 'social', label: t('pipelinq', 'Social'), icon: '\u2764' },
				{ value: 'brief', label: t('pipelinq', 'Letter'), icon: '\u2712' },
			],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isValid() {
			return this.form.subject.trim() !== '' && this.form.channel !== ''
		},
	},
	watch: {
		'form.channel'(newVal) {
			// Auto-start timer for phone channel
			if (newVal === 'telefoon') {
				this.$nextTick(() => {
					if (this.$refs.timer) this.$refs.timer.start()
				})
			}
		},
	},
	methods: {
		onTimerStopped(duration) {
			this.channelMeta.gespreksduur = duration
		},
		onTimerTick(duration) {
			this.channelMeta.gespreksduur = duration
		},
		buildChannelMetadata() {
			const metadata = {}
			if (this.form.channel === 'telefoon') {
				if (this.channelMeta.gespreksduur) metadata.gespreksduur = this.channelMeta.gespreksduur
				metadata.richting = this.channelMeta.richting
			} else if (this.form.channel === 'email') {
				if (this.channelMeta.afzender) metadata.afzender = this.channelMeta.afzender
				if (this.channelMeta.threadId) metadata.threadId = this.channelMeta.threadId
			} else if (this.form.channel === 'balie') {
				if (this.channelMeta.locatie) metadata.locatie = this.channelMeta.locatie
				if (this.channelMeta.volgnummer) metadata.volgnummer = this.channelMeta.volgnummer
			} else if (this.form.channel === 'chat') {
				if (this.channelMeta.platform) metadata.platform = this.channelMeta.platform
				if (this.channelMeta.transcriptLink) metadata.transcriptLink = this.channelMeta.transcriptLink
			} else if (this.form.channel === 'social') {
				if (this.channelMeta.platform) metadata.platform = this.channelMeta.platform
				if (this.channelMeta.berichtUrl) metadata.berichtUrl = this.channelMeta.berichtUrl
			} else if (this.form.channel === 'brief') {
				metadata.richting = this.channelMeta.richting
				if (this.channelMeta.kenmerk) metadata.kenmerk = this.channelMeta.kenmerk
			}
			if (this.channelMeta.initiatiefnemer) {
				metadata.initiatiefnemer = this.channelMeta.initiatiefnemer
			}
			return metadata
		},
		async save() {
			if (!this.isValid) return
			this.saving = true
			this.errorMessage = ''

			const data = {
				subject: this.form.subject.trim(),
				channel: this.form.channel,
				contactedAt: new Date().toISOString(),
				agent: OC.currentUser,
				channelMetadata: this.buildChannelMetadata(),
			}

			if (this.form.outcome) data.outcome = this.form.outcome
			if (this.form.summary) data.summary = this.form.summary.trim()
			if (this.form.client) data.client = this.form.client
			if (this.channelMeta.gespreksduur) data.duration = this.channelMeta.gespreksduur

			try {
				const result = await this.objectStore.saveObject('contactmoment', data)
				if (result) {
					showSuccess(t('pipelinq', 'Contact moment registered'))
					this.$router.push({ name: 'Contactmomenten' })
				} else {
					const error = this.objectStore.getError('contactmoment')
					this.errorMessage = error?.message || t('pipelinq', 'Failed to register contact moment')
					showError(this.errorMessage)
				}
			} catch (error) {
				this.errorMessage = error.message || t('pipelinq', 'Failed to register contact moment')
				showError(this.errorMessage)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.contactmoment-form { padding: 20px; max-width: 800px; margin: 0 auto; }

.contactmoment-form__header { margin-bottom: 20px; }

.contactmoment-form__body { display: flex; flex-direction: column; gap: 16px; }

.channel-selector { display: flex; gap: 4px; flex-wrap: wrap; }

.channel-button { padding: 8px 16px; border: 2px solid var(--color-border); border-radius: var(--border-radius-large); background: var(--color-main-background); cursor: pointer; display: flex; align-items: center; gap: 6px; transition: border-color 0.2s; }

.channel-button:hover { border-color: var(--color-primary-element); }

.channel-button--active { border-color: var(--color-primary-element); background: var(--color-primary-element-light); font-weight: 600; }

.channel-icon { font-size: 1.2em; }

.form-row--split { display: flex; gap: 16px; }

.form-col { flex: 1; }

.form-col label, .form-row > label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 0.9em; }

.form-select { width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-main-background); }

.form-row--actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }

.form-error { padding: 8px 12px; background: var(--color-error); color: white; border-radius: var(--border-radius); font-size: 13px; }
</style>
