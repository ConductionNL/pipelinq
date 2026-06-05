<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<!--
  TimerMobile — full-viewport offline timer for the time-tracker leaf.

  This view is a thin mobile presentation layer over the leaf's capture
  action. It does NOT own a data model — buffered captures are submitted
  to the time-tracker leaf via useSyncQueue on reconnect.

  Touch targets: Start / Pause / Resume / Stop buttons are ≥48×48 px
  per the mobile spec requirement.

  @spec openspec/changes/time-entry-mobile/tasks.md#task-3.1
-->
<template>
	<div class="timer-mobile">
		<SyncStatusBanner
			:is-offline="isOffline"
			:is-syncing="isSyncing"
			:pending-count="pendingCount" />

		<div class="timer-mobile__display" aria-live="polite" :aria-label="t('pipelinq', 'Elapsed time')">
			<span class="timer-mobile__clock">{{ elapsedFormatted }}</span>
			<span v-if="isRunning && !isPaused" class="timer-mobile__running-indicator" aria-hidden="true">
				●
			</span>
		</div>

		<div v-if="!isRunning" class="timer-mobile__context">
			<label for="timer-description" class="timer-mobile__label">
				{{ t('pipelinq', 'What are you working on?') }}
			</label>
			<NcTextField
				id="timer-description"
				:value.sync="description"
				:placeholder="t('pipelinq', 'Description (optional)')"
				class="timer-mobile__description-input" />

			<div class="timer-mobile__gps-row">
				<NcCheckboxRadioSwitch
					:checked.sync="gpsEnabled"
					type="checkbox">
					{{ t('pipelinq', 'Capture GPS location') }}
				</NcCheckboxRadioSwitch>
				<span v-if="gpsEnabled && isDenied" class="timer-mobile__gps-denied">
					{{ t('pipelinq', 'Location access denied') }}
				</span>
				<span v-else-if="gpsEnabled && latitude !== null" class="timer-mobile__gps-ok">
					{{ t('pipelinq', 'Location captured') }}
				</span>
			</div>
		</div>

		<div class="timer-mobile__actions">
			<NcButton
				v-if="!isRunning"
				type="primary"
				class="timer-mobile__btn timer-mobile__btn--start"
				:aria-label="t('pipelinq', 'Start timer')"
				@click="onStart">
				<template #icon>
					<Play :size="28" />
				</template>
				{{ t('pipelinq', 'Start') }}
			</NcButton>

			<template v-else>
				<NcButton
					v-if="!isPaused"
					type="secondary"
					class="timer-mobile__btn timer-mobile__btn--pause"
					:aria-label="t('pipelinq', 'Pause timer')"
					@click="onPause">
					<template #icon>
						<Pause :size="28" />
					</template>
					{{ t('pipelinq', 'Pause') }}
				</NcButton>

				<NcButton
					v-else
					type="secondary"
					class="timer-mobile__btn timer-mobile__btn--resume"
					:aria-label="t('pipelinq', 'Resume timer')"
					@click="onResume">
					<template #icon>
						<Play :size="28" />
					</template>
					{{ t('pipelinq', 'Resume') }}
				</NcButton>

				<NcButton
					type="error"
					class="timer-mobile__btn timer-mobile__btn--stop"
					:aria-label="t('pipelinq', 'Stop timer and save')"
					@click="onStop">
					<template #icon>
						<StopIcon :size="28" />
					</template>
					{{ t('pipelinq', 'Stop') }}
				</NcButton>
			</template>
		</div>

		<div v-if="lastSyncError" class="timer-mobile__sync-error" role="alert">
			{{ t('pipelinq', 'Sync error: {error}', { error: lastSyncError }) }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcCheckboxRadioSwitch } from '@conduction/nextcloud-vue'
import Play from 'vue-material-design-icons/Play.vue'
import Pause from 'vue-material-design-icons/Pause.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'

import SyncStatusBanner from '../../components/timer/SyncStatusBanner.vue'
import { useOfflineTimer } from '../../composables/useOfflineTimer.js'
import { useSyncQueue } from '../../composables/useSyncQueue.js'
import { useGeoLocation } from '../../composables/useGeoLocation.js'

export default {
	name: 'TimerMobile',

	components: {
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		SyncStatusBanner,
		Play,
		Pause,
		StopIcon,
	},

	setup() {
		const timer = useOfflineTimer({
			onCapture: () => {
				// Trigger an immediate flush when a capture is written.
				syncQueue.flush()
				syncQueue.refreshPendingCount()
			},
		})

		const syncQueue = useSyncQueue()
		const geo = useGeoLocation()

		return {
			// Timer state & controls.
			isRunning: timer.isRunning,
			isPaused: timer.isPaused,
			elapsed: timer.elapsed,
			elapsedFormatted: timer.elapsedFormatted,
			timerStart: timer.start,
			timerPause: timer.pause,
			timerResume: timer.resume,
			timerStop: timer.stop,
			// Sync state.
			isSyncing: syncQueue.isSyncing,
			pendingCount: syncQueue.pendingCount,
			lastSyncError: syncQueue.lastError,
			// GPS.
			geoCapture: geo.capturePosition,
			isDenied: geo.isDenied,
			latitude: geo.latitude,
		}
	},

	data() {
		return {
			/** Optional description for the capture. */
			description: '',
			/** Whether the user wants GPS captured at start. */
			gpsEnabled: false,
			/** Whether the device is offline. */
			isOffline: !navigator.onLine,
		}
	},

	mounted() {
		window.addEventListener('online', this.onOnline)
		window.addEventListener('offline', this.onOffline)
	},

	beforeDestroy() {
		window.removeEventListener('online', this.onOnline)
		window.removeEventListener('offline', this.onOffline)
	},

	methods: {
		/**
		 * Start the timer, capturing GPS if enabled.
		 *
		 * @return {Promise<void>}
		 */
		async onStart() {
			let metadata = {}
			if (this.gpsEnabled && !this.isDenied) {
				// Capture GPS in parallel with starting the timer.
				metadata = await this.geoCapture()
			}
			this.timerStart({
				register: 'pipelinq',
				schema: 'client',
				// Use a placeholder objectId when no context is selected — the leaf
				// link endpoint requires an object UUID. In a real deployment this
				// would come from a context picker (out of scope for V1 mobile spec).
				objectId: 'mobile-unlinked',
				description: this.description,
				metadata,
			})
			this.description = ''
		},

		/**
		 * Pause the timer.
		 */
		onPause() {
			this.timerPause()
		},

		/**
		 * Resume a paused timer.
		 */
		onResume() {
			this.timerResume()
		},

		/**
		 * Stop the timer and persist the capture.
		 *
		 * @return {Promise<void>}
		 */
		async onStop() {
			await this.timerStop()
		},

		onOnline() {
			this.isOffline = false
		},

		onOffline() {
			this.isOffline = true
		},
	},
}
</script>

<style scoped>
.timer-mobile {
	display: flex;
	flex-direction: column;
	min-height: 100dvh;
	min-height: 100vh;
	padding: 16px;
	gap: 16px;
	box-sizing: border-box;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	max-width: 600px;
	margin: 0 auto;
}

.timer-mobile__display {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 32px 16px;
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-dark);
}

.timer-mobile__clock {
	font-size: clamp(2.5rem, 10vw, 4rem);
	font-variant-numeric: tabular-nums;
	font-family: var(--font-face-monospace, monospace);
	letter-spacing: 0.05em;
}

.timer-mobile__running-indicator {
	color: var(--color-error, #e9322d);
	font-size: 1.5rem;
	animation: blink 1.2s step-end infinite;
}

@keyframes blink {
	0%, 100% { opacity: 1; }
	50% { opacity: 0; }
}

.timer-mobile__context {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.timer-mobile__label {
	font-weight: 500;
	font-size: 0.9rem;
	color: var(--color-text-maxcontrast);
}

.timer-mobile__description-input {
	width: 100%;
}

.timer-mobile__gps-row {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.timer-mobile__gps-denied {
	color: var(--color-error, #e9322d);
	font-size: 0.8rem;
}

.timer-mobile__gps-ok {
	color: var(--color-success, #46ba61);
	font-size: 0.8rem;
}

.timer-mobile__actions {
	display: flex;
	gap: 12px;
	justify-content: center;
	flex-wrap: wrap;
	margin-top: auto;
}

/* ≥48×48 px touch targets per spec. */
.timer-mobile__btn {
	min-width: 120px;
	min-height: 48px !important;
	font-size: 1.1rem;
}

.timer-mobile__btn--start {
	min-width: 160px;
}

.timer-mobile__sync-error {
	color: var(--color-error, #e9322d);
	font-size: 0.85rem;
	text-align: center;
	padding: 8px;
	border-radius: var(--border-radius-element, 6px);
	background-color: var(--color-error-bg, #fce8e8);
}

@media (max-width: 768px) {
	.timer-mobile {
		padding: 12px;
	}

	.timer-mobile__actions {
		flex-direction: column;
		align-items: stretch;
	}

	.timer-mobile__btn {
		width: 100%;
		min-width: unset;
	}
}
</style>
